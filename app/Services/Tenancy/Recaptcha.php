<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google reCAPTCHA v3 on the sign-in and sign-up pages: an invisible score instead of a puzzle. The keys are the platform's
 * (Platform > Settings > Bot protection); the secret is stored encrypted and never leaves the server. Off until both keys are saved.
 */
class Recaptcha
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /** @return array{enabled: bool, site_key: string, secret_set: bool, threshold: float} never the secret itself */
    public function current(): array
    {
        return [
            'enabled' => PlatformSetting::get('recaptcha.enabled') === '1',
            'site_key' => (string) PlatformSetting::get('recaptcha.site_key'),
            'secret_set' => (bool) PlatformSetting::get('recaptcha.secret'),
            'threshold' => $this->threshold(),
        ];
    }

    /**
     * An empty secret keeps the saved one; `clear_secret` removes it.
     *
     * @param  array<string, mixed>  $d
     */
    public function save(array $d): void
    {
        PlatformSetting::put('recaptcha.enabled', ! empty($d['enabled']) ? '1' : '0');
        PlatformSetting::put('recaptcha.site_key', ($d['site_key'] ?? null) === null || $d['site_key'] === '' ? null : trim((string) $d['site_key']));
        PlatformSetting::put('recaptcha.threshold', (string) round(min(0.9, max(0.1, (float) ($d['threshold'] ?? 0.5))), 2));
        if (! empty($d['secret'])) {
            PlatformSetting::put('recaptcha.secret', Crypt::encryptString(trim((string) $d['secret'])));
        }
        if (! empty($d['clear_secret'])) {
            PlatformSetting::put('recaptcha.secret', null);
        }
    }

    public function threshold(): float
    {
        $value = (float) PlatformSetting::get('recaptcha.threshold', 0.5);

        return $value >= 0.1 && $value <= 0.9 ? $value : 0.5;
    }

    /** Switched on, with both keys saved (and the secret readable: one saved under another APP_KEY counts as not set). */
    public function active(): bool
    {
        return $this->siteKey() !== null && $this->secret() !== null;
    }

    /** What the pages need to load the script: the public key, or null while it is off. */
    public function siteKey(): ?string
    {
        $c = $this->current();

        return $c['enabled'] && $c['site_key'] !== '' && $c['secret_set'] && $this->secret() !== null ? $c['site_key'] : null;
    }

    private function secret(): ?string
    {
        $saved = PlatformSetting::get('recaptcha.secret');
        if (! $saved) {
            return null;
        }
        try {
            return Crypt::decryptString($saved);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Is this request from a person? True when reCAPTCHA is off. A missing or invalid token, a token made for another action, or a
     * score under the threshold is refused. If Google itself cannot be reached the request is let through (and logged): a Google
     * outage must not lock everybody out, and the other protections (throttles, lockouts, the honeypot) still stand.
     */
    public function passes(Request $request, string $action): bool
    {
        if (! $this->active()) {
            return true;
        }
        $token = (string) $request->input('recaptcha_token');
        if ($token === '') {
            return false;
        }

        try {
            $answer = Http::asForm()->timeout(5)->post(self::VERIFY_URL, ['secret' => $this->secret(), 'response' => $token, 'remoteip' => $request->ip()]);
        } catch (Throwable $e) {
            report($e);

            return true;
        }
        if ($answer->serverError()) {
            report(new \RuntimeException('reCAPTCHA answered with '.$answer->status()));

            return true;
        }

        $r = $answer->json() ?? [];

        return ($r['success'] ?? false) === true && ($r['action'] ?? null) === $action && (float) ($r['score'] ?? 0) >= $this->threshold();
    }
}
