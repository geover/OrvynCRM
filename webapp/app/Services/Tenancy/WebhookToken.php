<?php

namespace App\Services\Tenancy;

use App\Models\PublicLink;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * The secret in the URL that SendGrid / Twilio call back with. It is per company: the plain token is kept in the
 * company's own settings (so an administrator can copy the URL), and only its hash is registered centrally, which is
 * how a callback with no session finds its company (see ResolvePublicTenant).
 */
class WebhookToken
{
    private const KEY = 'integrations.webhook_token';

    public static function current(): string
    {
        $token = Setting::get(self::KEY);
        if (! $token) {
            $token = self::rotate();
        }

        return $token;
    }

    /** New token; the old one stops working immediately. */
    public static function rotate(): string
    {
        if ($old = Setting::get(self::KEY)) {
            PublicLink::forget(hash('sha256', $old));
        }
        $token = Str::random(40);
        Setting::put(self::KEY, $token);
        PublicLink::register(PublicLink::WEBHOOK, hash('sha256', $token));

        return $token;
    }

    /** @return array{sendgrid: string, twilio: string} the full callback URLs to paste into the providers */
    public static function urls(): array
    {
        $token = self::current();

        return [
            'sendgrid' => url('/api/v1/hooks/sendgrid').'?token='.$token,
            'twilio' => url('/api/v1/hooks/twilio').'?token='.$token,
        ];
    }
}
