<?php

namespace App\Services\Tenancy;

use App\Models\CompanyBilling;
use App\Models\CompanySubscription;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Licence tiers and the free trial. A tier fixes how many active users, how many texts a month and how many texting numbers
 * a company gets (config/plans.php); a platform administrator sets what each tier costs. A company that signed up gets a
 * trial (three months unless the platform changes it) on the first tier; when it ends the company must give its billing
 * details before it can carry on. Companies without a trial date (every company that existed before, and those the
 * platform created by hand) are never forced.
 */
class Plans
{
    public function __construct(private readonly Pricing $pricing, private readonly PaymentSettings $payments) {}

    // ---- the tiers ---------------------------------------------------------------------------------------------------------------

    /** @return list<array{tier: int, name: string, users: int|null, sms_per_month: int, sms_numbers: int, price: float|null}> */
    public function tiers(): array
    {
        $out = [];
        foreach (config('plans.tiers') as $n => $t) {
            $out[] = ['tier' => (int) $n, 'name' => $t['name'], 'users' => $t['users'], 'sms_per_month' => $t['sms_per_month'], 'sms_numbers' => $t['sms_numbers'], 'price' => $this->price((int) $n)];
        }

        return $out;
    }

    /** @return array{tier: int, name: string, users: int|null, sms_per_month: int, sms_numbers: int, price: float|null} */
    public function tier(int $tier): array
    {
        return collect($this->tiers())->firstWhere('tier', $this->clampTier($tier));
    }

    public function clampTier(int $tier): int
    {
        return array_key_exists($tier, config('plans.tiers')) ? $tier : (int) config('plans.trial_tier');
    }

    public function price(int $tier): ?float
    {
        $v = PlatformSetting::get("plans.price.{$tier}");

        return $v === null || $v === '' ? null : round((float) $v, 2);
    }

    /** @return array{currency: string, period: string} the currency and billing period are shared with the Pricing page */
    public function money(): array
    {
        $p = $this->pricing->current();

        return ['currency' => $p['currency'], 'period' => $p['period']];
    }

    // ---- the platform's settings ---------------------------------------------------------------------------------------------------

    public function trialMonths(): int
    {
        $m = (int) PlatformSetting::get('plans.trial_months', (string) config('plans.default_trial_months'));

        return $m >= 1 && $m <= 24 ? $m : (int) config('plans.default_trial_months');
    }

    /**
     * @param  array{trial_months: int, prices: array<int|string, float|int|string|null>}  $d
     */
    public function saveSettings(array $d): void
    {
        PlatformSetting::put('plans.trial_months', (string) min(24, max(1, (int) $d['trial_months'])));
        foreach (array_keys(config('plans.tiers')) as $n) {
            $v = $d['prices'][$n] ?? null;
            PlatformSetting::put("plans.price.{$n}", $v === null || $v === '' ? null : number_format((float) $v, 2, '.', ''));
        }
    }

    // ---- a company's tier and trial ------------------------------------------------------------------------------------------------

    /** Seats a tier stands for (the stored limit): its users, or the very large number that means unlimited. */
    public function seatsFor(int $tier): int
    {
        return $this->tier($tier)['users'] ?? (int) config('plans.unlimited_users');
    }

    public static function isUnlimitedSeats(?int $max): bool
    {
        return $max !== null && $max >= (int) config('plans.unlimited_users');
    }

    /** Put a company on a tier: its user limit becomes the tier's. (Extra users granted by a request are replaced.) */
    public function applyTier(Tenant $tenant, int $tier): void
    {
        $tier = $this->clampTier($tier);
        $tenant->update(['tier' => $tier, 'max_users' => $this->seatsFor($tier)]);

        // a smaller tier allows fewer texting numbers: keep the first ones (the default comes first)
        $allowed = $this->tier($tier)['sms_numbers'];
        try {
            $tenant->run(fn () => app(SmsNumbers::class)->trimTo($allowed));
        } catch (Throwable $e) {
            report($e);                                    // the numbers are a nicety: moving the company to the tier must not fail over them
        }
    }

    /** A new company's trial: the trial tier, ending the chosen number of months from now. */
    public function startTrial(Tenant $tenant, ?int $months = null): void
    {
        $this->applyTier($tenant, (int) config('plans.trial_tier'));
        $tenant->update(['trial_ends_at' => now()->addMonthsNoOverflow($months ?? $this->trialMonths()), 'trial_notice_stage' => 0]);
    }

    public function billing(Tenant $tenant): ?CompanyBilling
    {
        return CompanyBilling::where('tenant_id', $tenant->getTenantKey())->first();
    }

    public function billingComplete(Tenant $tenant): bool
    {
        return (bool) $this->billing($tenant)?->isComplete();
    }

    /** Does the company have a subscription that is running (or in its grace period after a failed payment)? */
    public function isPaying(Tenant $tenant): bool
    {
        return CompanySubscription::where('tenant_id', $tenant->getTenantKey())->whereIn('status', CompanySubscription::GOOD)->exists();
    }

    /**
     * What the platform asks after the trial: a running subscription, or complete billing details (when the platform does not
     * insist on a payment method, see Platform > Settings > Payments).
     */
    private function satisfied(bool $detailsComplete, bool $paying): bool
    {
        return $paying || ($detailsComplete && ! $this->payments->requirePayment());
    }

    /** The trial is over and the company has neither a subscription nor (where enough) its billing details: it must give them before it can go on. */
    public function isBlocked(Tenant $tenant): bool
    {
        if ($tenant->trial_ends_at === null || ! $tenant->trial_ends_at->isPast()) {
            return false;
        }

        return ! $this->satisfied($this->billingComplete($tenant), $this->isPaying($tenant));
    }

    /**
     * none: no trial (never forced); trial: running; expired: over and what is asked is missing; subscribed: over and in order.
     * `billing_complete` is about the details, `paying` about a running subscription, `ok` about what the platform asks.
     *
     * @return array{state: 'none'|'trial'|'expired'|'subscribed', ends_at: string|null, days_left: int|null, billing_complete: bool, paying: bool, ok: bool, payment_required: bool, blocked: bool}
     */
    public function state(Tenant $tenant, ?bool $billingComplete = null, ?bool $paying = null): array
    {
        $complete = $billingComplete ?? $this->billingComplete($tenant);
        $paying ??= $this->isPaying($tenant);
        $ok = $this->satisfied($complete, $paying);
        $ends = $tenant->trial_ends_at;
        $over = $ends !== null && $ends->isPast();
        $state = match (true) {
            $ends === null => 'none',
            $over && $ok => 'subscribed',
            $over => 'expired',
            default => 'trial',
        };

        return [
            'state' => $state, 'ends_at' => $ends?->toIso8601String(),
            'days_left' => $ends === null || $over ? null : (int) ceil(now()->diffInHours($ends, false) / 24),
            'billing_complete' => $complete, 'paying' => $paying, 'ok' => $ok, 'payment_required' => $this->payments->requirePayment() && ! $paying,
            'blocked' => $state === 'expired',
        ];
    }

    // ---- texts ------------------------------------------------------------------------------------------------------------------------

    public function smsLimit(Tenant $tenant): int
    {
        return $this->tier((int) $tenant->tier)['sms_per_month'];
    }

    public function smsNumbersMax(Tenant $tenant): int
    {
        return $this->tier((int) $tenant->tier)['sms_numbers'];
    }

    /** Texts that have gone out (or are going out) in the current calendar month of the company's time zone. */
    public function smsUsed(Tenant $tenant, ?int $exceptMessageId = null): int
    {
        return $tenant->run(function () use ($exceptMessageId) {
            $tz = (string) (Setting::get('app.timezone') ?: config('app.timezone'));
            $start = Carbon::now($tz)->startOfMonth()->utc();

            return SmsMessage::whereNotIn('status', ['queued', 'failed'])->whereRaw('COALESCE(sent_at, updated_at) >= ?', [$start])
                ->when($exceptMessageId, fn ($q) => $q->whereKeyNot($exceptMessageId))->count();
        });
    }

    /** Has the company used up this month's texts? (`$exceptMessageId`: the text being sent, which must not count against itself.) */
    public function smsExhausted(Tenant $tenant, ?int $exceptMessageId = null): bool
    {
        return $this->smsUsed($tenant, $exceptMessageId) >= $this->smsLimit($tenant);
    }

    /** The texting numbers the company uses (the ones it chose, else the single number of its account). */
    public function numbersUsed(Tenant $tenant): int
    {
        return $tenant->run(fn () => count(app(SmsNumbers::class)->usable()));
    }

    // ---- what the screens show ----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> a company's plan, trial and usage in one piece */
    public function summary(Tenant $tenant): array
    {
        $tier = $this->tier((int) $tenant->tier);

        return [
            'tier' => $tier, 'trial' => $this->state($tenant), 'money' => $this->money(),
            'users' => ['used' => app(Seats::class)->usedFor($tenant), 'max' => self::isUnlimitedSeats((int) $tenant->max_users) ? null : (int) $tenant->max_users],
            'sms' => ['used' => $this->smsUsed($tenant), 'max' => $tier['sms_per_month']],
            'numbers' => ['used' => $this->numbersUsed($tenant), 'max' => $tier['sms_numbers']],
        ];
    }
}
