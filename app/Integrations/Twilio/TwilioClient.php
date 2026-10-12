<?php

namespace App\Integrations\Twilio;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin REST client (no SDK needed): credentials live encrypted in the integrations table. */
class TwilioClient
{
    public function send(string $to, string $body, ?string $from = null): string
    {
        $integration = Integration::for('twilio') ?? throw new RuntimeException('Twilio integration is not enabled.');
        $c = $integration->credentials;

        $response = Http::asForm()->withBasicAuth($c['account_sid'], $c['auth_token'])->timeout(15)->retry(2, 500)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['account_sid']}/Messages.json", array_filter([
                'To' => $to,
                'From' => $from ?: ($integration->settings['from_number'] ?? null),
                'Body' => $body,
            ]));

        if ($response->failed()) {
            throw new RuntimeException('Twilio error: '.($response->json('message') ?? $response->status()));
        }

        return $response->json('sid');
    }
}
