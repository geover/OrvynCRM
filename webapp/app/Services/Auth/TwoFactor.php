<?php

namespace App\Services\Auth;

use App\Models\AccountUser;
use App\Support\NoticeMail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two-step verification with an authenticator app: time-based one-time passwords (RFC 6238: HMAC-SHA1, 6 digits, 30 seconds), the format
 * every authenticator app understands, plus single-use recovery codes for a lost phone. A code is accepted once only (replay protection)
 * and wrong guesses are rate limited. The secret is stored encrypted; recovery codes are stored as keyed hashes, so even the database
 * cannot give them away.
 */
class TwoFactor
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public const RECOVERY_COUNT = 10;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** The code alphabet leaves out look-alikes (0/o, 1/l/i). */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** What the QR code carries: scanning it adds the account to the authenticator app. */
    public function uri(AccountUser $user, string $secret, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($user->email);

        return "otpauth://totp/{$label}?".http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    /** The code for one 30-second step. */
    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0xF;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step the code belongs to (the one before and after the current one are accepted: clocks drift), or null when it is wrong or
     * is not newer than $lastStep (a code that was already used).
     */
    public function matchingStep(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $current = intdiv($now ?? time(), self::PERIOD);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (hash_equals($this->code($secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                return $step;
            }
        }

        return null;
    }

    public function isEnabled(AccountUser $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    /** Checks the code the person typed (or a recovery code) and remembers it was used. Rate limited per person. */
    public function verify(AccountUser $user, string $input): bool
    {
        $key = 'two-factor:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return false;
        }
        $ok = $this->check($user, $input);
        $ok ? RateLimiter::clear($key) : RateLimiter::hit($key, 300);

        return $ok;
    }

    public function lockedOut(AccountUser $user): bool
    {
        return RateLimiter::tooManyAttempts('two-factor:'.$user->getKey(), 5);
    }

    /**
     * Saves the secret as confirmed once the person proves their app shows the right code.
     *
     * @return list<string>|null the recovery codes, to be shown once; null when the code was wrong
     */
    public function confirm(AccountUser $user, string $secret, string $code): ?array
    {
        $step = $this->matchingStep($secret, $code);
        if ($step === null) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => json_encode(array_map($this->hashCode(...), $codes)),
        ])->save();

        return $codes;
    }

    /** @return list<string> a fresh set (the old codes stop working) */
    public function regenerateRecoveryCodes(AccountUser $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_map($this->hashCode(...), $codes))])->save();

        return $codes;
    }

    public function disable(AccountUser $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    /** An administrator turns it off for somebody who lost their phone and their recovery codes; the person is told, and sets it up again when they sign in. */
    public function reset(AccountUser $user): void
    {
        $this->disable($user);
        NoticeMail::send($user->email, 'Two-step verification was reset', ["Hi {$user->name},", 'An administrator reset two-step verification on your account. You can sign in with your password now; set it up again in your sign-in security page. If you did not ask for this, tell your administrator.']);
    }

    public function recoveryCodesLeft(AccountUser $user): int
    {
        return count(json_decode((string) $user->two_factor_recovery_codes, true) ?: []);
    }

    private function check(AccountUser $user, string $input): bool
    {
        if (! $this->isEnabled($user)) {
            return false;
        }
        if ($step = $this->matchingStep($user->two_factor_secret, $input, $user->two_factor_last_step)) {
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        return $this->useRecoveryCode($user, $input);
    }

    private function useRecoveryCode(AccountUser $user, string $input): bool
    {
        $normalized = strtolower(preg_replace('/[\s-]+/', '', $input));
        if (strlen($normalized) !== 10) {
            return false;
        }
        $hash = $this->hashCode($normalized);
        $left = json_decode((string) $user->two_factor_recovery_codes, true) ?: [];
        foreach ($left as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($left[$i]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($left))])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> like "k3m9x-7qp2t" */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < self::RECOVERY_COUNT) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[$raw] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return array_values($codes);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', strtolower(str_replace('-', '', $code)), (string) config('app.key'));
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
