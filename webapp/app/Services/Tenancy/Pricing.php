<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;

/**
 * What an additional user, data object or automation costs, as set on the platform's Pricing page. Shown to companies when they ask for more
 * users. (Billing itself is added later: for now this is the quote.)
 */
class Pricing
{
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'AUD', 'NZD', 'CAD', 'SGD', 'JPY', 'INR', 'PHP', 'CHF', 'SEK', 'NOK', 'DKK', 'ZAR', 'BRL', 'MXN', 'AED'];

    public const PERIODS = ['month', 'year'];

    /** @return array{price: float|null, data_object_price: float|null, automation_price: float|null, currency: string, period: string, note: string|null} a price that is null has not been set */
    public function current(): array
    {
        $price = PlatformSetting::get('pricing.user_price');
        $currency = PlatformSetting::get('pricing.currency');
        $period = PlatformSetting::get('pricing.period');

        $money = fn (?string $v) => $v !== null && $v !== '' ? round((float) $v, 2) : null;

        return [
            'price' => $money($price),
            'data_object_price' => $money(PlatformSetting::get('pricing.data_object_price')),
            'automation_price' => $money(PlatformSetting::get('pricing.automation_price')),
            'currency' => in_array($currency, self::CURRENCIES, true) ? $currency : 'USD',
            'period' => in_array($period, self::PERIODS, true) ? $period : 'month',
            'note' => PlatformSetting::get('pricing.note') ?: null,
        ];
    }

    /**
     * @param  array{price: float|int|string|null, currency: string, period: string, note?: string|null}  $d
     */
    public function save(array $d): void
    {
        PlatformSetting::put('pricing.user_price', ($d['price'] ?? null) === null || $d['price'] === '' ? null : number_format((float) $d['price'], 2, '.', ''));
        foreach (['data_object_price', 'automation_price'] as $key) {
            if (array_key_exists($key, $d)) {
                PlatformSetting::put("pricing.{$key}", $d[$key] === null || $d[$key] === '' ? null : number_format((float) $d[$key], 2, '.', ''));
            }
        }
        PlatformSetting::put('pricing.currency', $d['currency']);
        PlatformSetting::put('pricing.period', $d['period']);
        PlatformSetting::put('pricing.note', trim((string) ($d['note'] ?? '')) ?: null);
    }

    /** "$10.00 per user per month", or null when no price is set. */
    public function describe(?array $p = null): ?string
    {
        $p ??= $this->current();
        if ($p['price'] === null) {
            return null;
        }

        return number_format($p['price'], 2).' '.$p['currency'].' per additional user per '.$p['period'];
    }
}
