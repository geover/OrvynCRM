<?php

namespace App\Services\Tenancy;

use App\Integrations\Twilio\TwilioClient;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The platform's own Twilio account for text messages. A company that has set up its own Twilio integration keeps using
 * it; every other company sends through this account when the platform has switched it on (see TwilioClient::account()).
 * The auth token is stored encrypted and never leaves the server.
 */
class SmsSettings
{
    /**
     * What is saved on the Settings page (the form's values) and what .env gives as the default. A field left empty here falls
     * back to its TWILIO_* value in .env; `effective` is what is really used. Never the token itself.
     *
     * @return array{enabled: bool, account_sid: string, token_set: bool, from_number: string, messaging_service_sid: string, env: array{account_sid: string, token_set: bool, from_number: string, messaging_service_sid: string}, effective: array{account_sid: string, from_number: string, messaging_service_sid: string}}
     */
    public function current(): array
    {
        return [
            'enabled' => $this->enabled(),
            'account_sid' => (string) PlatformSetting::get('sms.account_sid'),
            'token_set' => (bool) PlatformSetting::get('sms.auth_token'),
            'from_number' => (string) PlatformSetting::get('sms.from_number'),
            'messaging_service_sid' => (string) PlatformSetting::get('sms.messaging_service_sid'),
            'env' => [
                'account_sid' => $this->env('account_sid'), 'token_set' => $this->env('auth_token') !== '',
                'from_number' => $this->env('from_number'), 'messaging_service_sid' => $this->env('messaging_service_sid'),
            ],
            'effective' => [
                'account_sid' => $this->value('account_sid'), 'from_number' => $this->value('from_number'), 'messaging_service_sid' => $this->value('messaging_service_sid'),
            ],
        ];
    }

    /** Saved on the Settings page; until it has been saved once, on when .env holds a complete account. */
    public function enabled(): bool
    {
        $saved = PlatformSetting::get('sms.enabled');

        return $saved === null ? $this->envComplete() : $saved === '1';
    }

    private function env(string $key): string
    {
        return trim((string) config("services.twilio.{$key}"));
    }

    /** The saved value, else the one from .env. */
    private function value(string $key): string
    {
        return trim((string) PlatformSetting::get("sms.{$key}")) ?: $this->env($key);
    }

    private function envComplete(): bool
    {
        return $this->env('account_sid') !== '' && $this->env('auth_token') !== '' && ($this->env('from_number') !== '' || $this->env('messaging_service_sid') !== '');
    }

    /** The auth token: the saved (encrypted) one, else the one in .env. */
    private function token(): ?string
    {
        $saved = PlatformSetting::get('sms.auth_token');
        if ($saved) {
            try {
                return Crypt::decryptString($saved);
            } catch (Throwable) {
                return null;                                   // saved under another APP_KEY: treated as not set
            }
        }

        return $this->env('auth_token') ?: null;
    }

    /**
     * An empty token keeps the saved one; `clear_token` removes it.
     *
     * @param  array<string, mixed>  $d
     */
    public function save(array $d): void
    {
        PlatformSetting::put('sms.enabled', ! empty($d['enabled']) ? '1' : '0');
        foreach (['account_sid', 'from_number', 'messaging_service_sid'] as $key) {
            PlatformSetting::put("sms.{$key}", ($d[$key] ?? null) === null || $d[$key] === '' ? null : trim((string) $d[$key]));
        }
        if (! empty($d['auth_token'])) {
            PlatformSetting::put('sms.auth_token', Crypt::encryptString($d['auth_token']));
        }
        if (! empty($d['clear_token'])) {
            PlatformSetting::put('sms.auth_token', null);
        }
    }

    /**
     * What a company without its own Twilio account sends through, or null when the platform has not switched it on or the
     * account is incomplete (it needs the SID, the token, and a number or a messaging service to send from).
     *
     * @return array{sid: string, token: string, from: ?string, service: ?string}|null
     */
    public function account(): ?array
    {
        $sid = $this->value('account_sid');
        $token = $this->token();
        $from = $this->value('from_number');
        $service = $this->value('messaging_service_sid');
        if (! $this->enabled() || $sid === '' || ! $token || ($from === '' && $service === '')) {
            return null;
        }

        return ['sid' => $sid, 'token' => $token, 'from' => $from ?: null, 'service' => $service ?: null];
    }

    /** Send a text through the saved account, to prove it works. Returns Twilio's message id; throws with Twilio's own error. */
    public function sendTest(string $to): string
    {
        $account = $this->account() ?? throw new \RuntimeException('Save the account details and switch it on first.');

        return app(TwilioClient::class)->sendWith($account, $to, 'Test message from the platform settings. If you can read it, text messages work.');
    }
}
