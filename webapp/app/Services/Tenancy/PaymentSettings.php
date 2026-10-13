<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The platform's Stripe account (Platform > Settings > Payments). The secret key and the webhook signing secret are stored
 * encrypted and never leave the server; they can also come from the .env file (STRIPE_SECRET, STRIPE_WEBHOOK_SECRET). The
 * Stripe price of each tier is kept here too, so a company is always charged what the platform set.
 */
class PaymentSettings
{
    /** @return array{enabled: bool, key_set: bool, key_hint: string|null, mode: 'test'|'live'|null, webhook_set: bool, require_payment: bool, webhook_url: string} never the secrets themselves */
    public function current(): array
    {
        $key = $this->secretKeyValue();

        return [
            'enabled' => $this->enabled(),
            'key_set' => $key !== null,
            'key_hint' => $key ? '••••'.substr($key, -4) : null,
            'mode' => $this->mode(),
            'webhook_set' => $this->webhookSecretValue() !== null,
            'require_payment' => $this->requirePayment(),
            'webhook_url' => url('/api/v1/hooks/stripe'),
        ];
    }

    public function enabled(): bool
    {
        return PlatformSetting::get('stripe.enabled') === '1';
    }

    /** After the free trial, must a company have a payment method (a subscription) instead of only its billing details? */
    public function requirePayment(): bool
    {
        return $this->enabled() && PlatformSetting::get('stripe.require_payment') === '1';
    }

    /** The key to call Stripe with, or null when payments are off or no key is saved. */
    public function secretKey(): ?string
    {
        return $this->enabled() ? $this->secretKeyValue() : null;
    }

    public function webhookSecret(): ?string
    {
        return $this->enabled() ? $this->webhookSecretValue() : null;
    }

    /** @return 'test'|'live'|null */
    public function mode(): ?string
    {
        $key = $this->secretKeyValue();

        return $key === null ? null : (str_contains($key, '_test_') ? 'test' : 'live');
    }

    /**
     * An empty key keeps the saved one; `clear_key` removes it. Same for the signing secret.
     *
     * @param  array<string, mixed>  $d
     */
    public function save(array $d): void
    {
        PlatformSetting::put('stripe.enabled', ! empty($d['enabled']) ? '1' : '0');
        PlatformSetting::put('stripe.require_payment', ! empty($d['require_payment']) ? '1' : '0');
        foreach (['secret_key' => 'key', 'webhook_secret' => 'webhook'] as $field => $short) {
            if (! empty($d[$field])) {
                PlatformSetting::put("stripe.{$field}", Crypt::encryptString(trim($d[$field])));
            }
            if (! empty($d["clear_{$short}"])) {
                PlatformSetting::put("stripe.{$field}", null);
            }
        }
    }

    // ---- the Stripe price of each tier ------------------------------------------------------------------------------------------------

    public function priceId(int $tier): ?string
    {
        return PlatformSetting::get("stripe.price.{$tier}") ?: null;
    }

    /** What the saved Stripe price was made from (amount, currency, interval): when it differs, a new price is needed. */
    public function priceSignature(int $tier): ?string
    {
        return PlatformSetting::get("stripe.price_sig.{$tier}") ?: null;
    }

    public function productId(int $tier): ?string
    {
        return PlatformSetting::get("stripe.product.{$tier}") ?: null;
    }

    public function savePrice(int $tier, ?string $productId, ?string $priceId, ?string $signature): void
    {
        PlatformSetting::put("stripe.product.{$tier}", $productId);
        PlatformSetting::put("stripe.price.{$tier}", $priceId);
        PlatformSetting::put("stripe.price_sig.{$tier}", $signature);
    }

    private function secretKeyValue(): ?string
    {
        return $this->decrypt(PlatformSetting::get('stripe.secret_key')) ?? (config('services.stripe.secret') ?: null);
    }

    private function webhookSecretValue(): ?string
    {
        return $this->decrypt(PlatformSetting::get('stripe.webhook_secret')) ?? (config('services.stripe.webhook_secret') ?: null);
    }

    private function decrypt(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null;                                       // saved under another APP_KEY: treated as not set
        }
    }
}
