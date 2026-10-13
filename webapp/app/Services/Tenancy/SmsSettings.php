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
    /** @return array{enabled: bool, account_sid: string, token_set: bool, from_number: string, messaging_service_sid: string} never the token itself */
    public function current(): array
    {
        return [
            'enabled' => PlatformSetting::get('sms.enabled') === '1',
            'account_sid' => (string) PlatformSetting::get('sms.account_sid'),
            'token_set' => (bool) PlatformSetting::get('sms.auth_token'),
            'from_number' => (string) PlatformSetting::get('sms.from_number'),
            'messaging_service_sid' => (string) PlatformSetting::get('sms.messaging_service_sid'),
        ];
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
        $c = $this->current();
        $token = PlatformSetting::get('sms.auth_token');
        if (! $c['enabled'] || $c['account_sid'] === '' || ! $token || ($c['from_number'] === '' && $c['messaging_service_sid'] === '')) {
            return null;
        }

        try {
            return ['sid' => $c['account_sid'], 'token' => Crypt::decryptString($token), 'from' => $c['from_number'] ?: null, 'service' => $c['messaging_service_sid'] ?: null];
        } catch (Throwable) {
            return null;                                       // saved under another APP_KEY: treated as not set up
        }
    }

    /** Send a text through the saved account, to prove it works. Returns Twilio's message id; throws with Twilio's own error. */
    public function sendTest(string $to): string
    {
        $account = $this->account() ?? throw new \RuntimeException('Save the account details and switch it on first.');

        return app(TwilioClient::class)->sendWith($account, $to, 'Test message from the platform settings. If you can read it, text messages work.');
    }
}
