<?php

namespace App\Integrations\Twilio;

use App\Models\Integration;
use App\Services\Tenancy\SmsNumbers;
use App\Services\Tenancy\SmsSettings;
use App\Services\Tenancy\WebhookToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Thin REST client (no SDK needed). Which Twilio account sends is decided per company: its own integration (credentials
 * encrypted in the integrations table) when it has one, otherwise the platform's account (Platform > Settings > SMS).
 */
class TwilioClient
{
    /**
     * @return array{sid: string, token: string, from: ?string, service: ?string, source: 'company'|'platform'}|null null: nothing is set up
     */
    public function account(): ?array
    {
        if ($own = Integration::for('twilio')) {
            $c = (array) $own->credentials;

            return ['sid' => (string) ($c['account_sid'] ?? ''), 'token' => (string) ($c['auth_token'] ?? ''), 'from' => $this->defaultNumber() ?? $own->settings['from_number'] ?? null,
                'service' => $own->settings['messaging_service_sid'] ?? null, 'source' => 'company'];
        }

        $platform = app(SmsSettings::class)->account();

        if ($platform && ($number = $this->defaultNumber())) {
            $platform['from'] = $number;                 // the number this company chose among the ones its plan allows
        }

        return $platform ? $platform + ['source' => 'platform'] : null;
    }

    /** The company's chosen default texting number, if it has chosen any. */
    private function defaultNumber(): ?string
    {
        return tenancy()->initialized ? app(SmsNumbers::class)->default() : null;
    }

    /** Can this company send text messages at all (its own account, or the platform's)? */
    public function available(): bool
    {
        return $this->account() !== null;
    }

    public function send(string $to, string $body, ?string $from = null): string
    {
        $account = $this->account() ?? throw new RuntimeException('Text messages are not set up. Ask your platform administrator to switch them on.');

        return $this->sendWith($account, $to, $body, $from);
    }

    /**
     * @param  array{sid: string, token: string, from: ?string, service: ?string}  $account
     */
    public function sendWith(array $account, string $to, string $body, ?string $from = null): string
    {
        $fields = array_filter([
            'To' => $to, 'Body' => $body,
            // a messaging service picks the number itself; otherwise the person's number, then the account's
            'MessagingServiceSid' => $account['service'] ?? null,
            'From' => $account['service'] ?? null ? null : ($from ?: ($account['from'] ?? null)),
            'StatusCallback' => $this->statusCallback(),
        ]);

        $response = Http::asForm()->withBasicAuth($account['sid'], $account['token'])->timeout(15)
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$account['sid']}/Messages.json", $fields);

        if ($response->failed()) {
            throw new RuntimeException('Twilio error: '.($response->json('message') ?? $response->status()));
        }

        return $response->json('sid');
    }

    /**
     * Ask Twilio for the account itself: proves the SID and token are accepted. Throws with Twilio's own error.
     *
     * @param  array{sid: string, token: string}|null  $account
     */
    public function ping(?array $account): void
    {
        $account ?? throw new RuntimeException('No Twilio account is set up.');
        $response = Http::withBasicAuth($account['sid'], $account['token'])->timeout(15)->get("https://api.twilio.com/2010-04-01/Accounts/{$account['sid']}.json");
        if ($response->failed()) {
            throw new RuntimeException($response->json('message') ?? 'HTTP '.$response->status());
        }
    }

    /** Where Twilio reports delivery: the company's own callback URL, so the status lands on the right company's message. */
    private function statusCallback(): ?string
    {
        if (! tenancy()->initialized) {
            return null;
        }
        try {
            $url = WebhookToken::urls()['twilio'];
        } catch (Throwable) {
            return null;
        }

        // Twilio refuses a callback it cannot reach (localhost, a bare IP, a .test address): send none rather than fail the text
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/\.(test|local|localhost|invalid|example)$/', $host) ? null : $url;
    }
}
