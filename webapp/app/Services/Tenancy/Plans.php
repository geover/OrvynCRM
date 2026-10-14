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

    /**
     * The tiers as they are now: what config/plans.php says, with whatever a platform administrator changed on Plans & trial laid over it.
     * `users` null means unlimited.
     *
     * @return list<array{tier: int, name: string, users: int|null, sms_per_month: int, sms_numbers: int, price: float|null, customised: bool}>
     */
    public function tiers(): array
    {
        $out = [];
        foreach (config('plans.tiers') as $n => $t) {
            $n = (int) $n;
            $users = PlatformSetting::get("plans.tier.{$n}.users");
            $name = trim((string) PlatformSetting::get("plans.tier.{$n}.name"));
            $sms = PlatformSetting::get("plans.tier.{$n}.sms");
            $numbers = PlatformSetting::get("plans.tier.{$n}.numbers");
            $row = [
                'tier' => $n, 'name' => $name !== '' ? $name : $t['name'],
                'users' => $users === null || $users === '' ? $t['users'] : ($users === 'unlimited' ? null : (int) $users),
                'sms_per_month' => $sms === null || $sms === '' ? $t['sms_per_month'] : (int) $sms,
                'sms_numbers' => $numbers === null || $numbers === '' ? $t['sms_numbers'] : (int) $numbers,
                'price' => $this->price($n),
            ];
            $row['customised'] = $row['name'] !== $t['name'] || $row['users'] !== $t['users'] || $row['sms_per_month'] !== $t['sms_per_month'] || $row['sms_numbers'] !== $t['sms_numbers'];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * What differs between two sets of tiers (as `tiers()` returns them), tier by tier, in words a person can read.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array{tier: int, name: string, was: string, changes: list<array{label: string, from: string, to: string}>}>
     */
    public function diff(array $before, array $after): array
    {
        $money = $this->money();
        $users = fn ($v) => $v === null ? 'unlimited' : number_format((int) $v);
        $price = fn ($v) => $v === null ? 'no price' : number_format((float) $v, 2).' '.$money['currency'].' per '.$money['period'];
        $was = collect($before)->keyBy('tier');
        $out = [];
        foreach ($after as $t) {
            $b = $was[$t['tier']] ?? null;
            if (! $b) {
                continue;
            }
            $changes = [];
            $b['name'] !== $t['name'] && $changes[] = ['label' => 'Name', 'from' => $b['name'], 'to' => $t['name']];
            $b['users'] !== $t['users'] && $changes[] = ['label' => 'Active users', 'from' => $users($b['users']), 'to' => $users($t['users'])];
            $b['sms_per_month'] !== $t['sms_per_month'] && $changes[] = ['label' => 'Texts per month', 'from' => number_format($b['sms_per_month']), 'to' => number_format($t['sms_per_month'])];
            $b['sms_numbers'] !== $t['sms_numbers'] && $changes[] = ['label' => 'Texting numbers', 'from' => (string) $b['sms_numbers'], 'to' => (string) $t['sms_numbers']];
            $b['price'] !== $t['price'] && $changes[] = ['label' => 'Price', 'from' => $price($b['price']), 'to' => $price($t['price'])];
            $changes && $out[] = ['tier' => $t['tier'], 'name' => $t['name'], 'was' => $b['name'], 'changes' => $changes];
        }

        return $out;
    }

    /** @return list<array{tier: int, name: string, users: int|null, sms_per_month: int, sms_numbers: int}> what config/plans.php ships with */
    public function defaults(): array
    {
        return collect(config('plans.tiers'))->map(fn ($t, $n) => ['tier' => (int) $n] + $t)->values()->all();
    }

    /**
     * Change what the tiers include. Every company on a tier follows at once: its users (a company with extra users keeps the extra),
     * its texts and texting numbers (read live; numbers beyond the new allowance are dropped, the default first). Nobody is removed.
     *
     * @param  array<int|string, array{name: string, users: int|null, sms_per_month: int, sms_numbers: int}>  $new
     * @return array{companies: int, trimmed: int} how many companies were updated and how many texting numbers dropped
     */
    public function saveTiers(array $new): array
    {
        $old = collect($this->tiers())->keyBy('tier');
        foreach (array_keys(config('plans.tiers')) as $n) {
            $t = $new[$n] ?? $new[(string) $n] ?? null;
            if ($t === null) {
                continue;
            }
            PlatformSetting::put("plans.tier.{$n}.name", trim((string) $t['name']));
            PlatformSetting::put("plans.tier.{$n}.users", ($t['users'] ?? null) === null ? 'unlimited' : (string) (int) $t['users']);
            PlatformSetting::put("plans.tier.{$n}.sms", (string) (int) $t['sms_per_month']);
            PlatformSetting::put("plans.tier.{$n}.numbers", (string) (int) $t['sms_numbers']);
        }

        return $this->followTiers($old->all());
    }

    /** Back to what config/plans.php ships with. */
    public function resetTiers(): array
    {
        $old = collect($this->tiers())->keyBy('tier');
        foreach (array_keys(config('plans.tiers')) as $n) {
            foreach (['name', 'users', 'sms', 'numbers'] as $k) {
                PlatformSetting::put("plans.tier.{$n}.{$k}", null);
            }
        }

        return $this->followTiers($old->all());
    }

    /** Make every company's stored limits follow the tiers as they are now. @param array<int, array<string, mixed>> $old */
    private function followTiers(array $old): array
    {
        $updated = 0;
        $trimmed = 0;
        foreach (Tenant::all() as $tenant) {
            $n = (int) $tenant->tier;
            $was = $old[$n] ?? null;
            $now = $this->tier($n);
            if (! $was) {
                continue;
            }
            $max = $this->seatsAfter((int) $tenant->max_users, $was['users'], $now['users']);
            $changed = $max !== (int) $tenant->max_users;
            $changed && $tenant->update(['max_users' => $max]);
            $drop = $this->trimNumbers($tenant, $now['sms_numbers'] + (int) $tenant->extra_numbers);
            $trimmed += $drop;
            ($changed || $drop) && $updated++;
        }

        return ['companies' => $updated, 'trimmed' => $trimmed];
    }

    /** A company's stored user limit after its tier changed: it follows the tier, and a company that had extra users keeps the extra. */
    private function seatsAfter(int $max, ?int $oldUsers, ?int $newUsers): int
    {
        $unlimited = (int) config('plans.unlimited_users');
        $oldBase = $oldUsers ?? $unlimited;
        $newBase = $newUsers ?? $unlimited;

        return match (true) {
            $oldBase === $newBase => $max,
            $max <= $oldBase || $oldBase >= $unlimited => $newBase,
            $newBase >= $unlimited => $unlimited,
            default => $newBase + ($max - $oldBase),
        };
    }

    /** Drop the texting numbers a company has beyond what it is allowed now (tier plus extras): the first ones, the default, stay. */
    public function enforceNumbers(Tenant $tenant): int
    {
        return $this->trimNumbers($tenant, $this->smsNumbersMax($tenant));
    }

    /** Drop texting numbers beyond `$allowed` (the first ones stay). @return int how many were dropped */
    private function trimNumbers(Tenant $tenant, int $allowed): int
    {
        try {
            return $tenant->run(fn () => app(SmsNumbers::class)->trimTo($allowed));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * What saving these tiers would do, before it is saved: how many companies are on each tier, and which companies would then be above a limit.
     * Nothing is changed; nobody is removed when it is saved either, they are simply at a limit until they free something or move up.
     *
     * @param  array<int|string, array{name?: string, users: int|null, sms_per_month: int, sms_numbers: int}>  $proposed
     * @return array{companies: array<int, int>, over: list<array{company: string, tier: string, what: string, used: int, limit: int}>}
     */
    public function impact(array $proposed): array
    {
        $over = [];
        $counts = [];
        foreach (Tenant::active()->get() as $tenant) {
            $n = (int) $tenant->tier;
            $counts[$n] = ($counts[$n] ?? 0) + 1;
            $t = $proposed[$n] ?? $proposed[(string) $n] ?? null;
            if (! $t) {
                continue;
            }
            $name = ($t['name'] ?? '') !== '' ? $t['name'] : $this->tier($n)['name'];
            $users = app(Seats::class)->usedFor($tenant);
            $was = $this->tier($n);
            $limit = ($t['users'] ?? null) === null ? null : $this->seatsAfter((int) $tenant->max_users, $was['users'], (int) $t['users']);
            $limit !== null && $users > $limit && $over[] = ['company' => $tenant->name, 'tier' => $name, 'what' => 'active users', 'used' => $users, 'limit' => $limit];
            $numbers = $this->numbersUsed($tenant);
            $numLimit = (int) $t['sms_numbers'] + (int) $tenant->extra_numbers;
            $numbers > $numLimit && $over[] = ['company' => $tenant->name, 'tier' => $name, 'what' => 'texting numbers (extra ones are dropped)', 'used' => $numbers, 'limit' => $numLimit];
            $sms = $this->smsUsed($tenant);
            $smsLimit = (int) $t['sms_per_month'] + $this->extraSms($tenant);
            $sms > $smsLimit && $over[] = ['company' => $tenant->name, 'tier' => $name, 'what' => 'texts this month', 'used' => $sms, 'limit' => $smsLimit];
        }

        return ['companies' => $counts, 'over' => $over];
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
        $this->trimNumbers($tenant, $this->tier($tier)['sms_numbers'] + (int) $tenant->extra_numbers);
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

    /** Texts a month: what the tier includes plus any extra the platform gave this company. */
    public function smsLimit(Tenant $tenant): int
    {
        return $this->tier((int) $tenant->tier)['sms_per_month'] + $this->extraSms($tenant);
    }

    /** The extra texts a company has right now: its permanent extra, plus the one-month bonus while that month lasts. */
    public function extraSms(Tenant $tenant): int
    {
        return (int) $tenant->extra_sms + $this->bonusSms($tenant);
    }

    /** The one-month bonus, only while its month is the current one (it ends by itself afterwards, nothing has to run). */
    public function bonusSms(Tenant $tenant): int
    {
        return (int) $tenant->bonus_sms > 0 && $tenant->bonus_sms_month === $this->currentMonth($tenant) ? (int) $tenant->bonus_sms : 0;
    }

    /** The calendar month the company is in, in its own time zone (2026-11). */
    public function currentMonth(Tenant $tenant): string
    {
        return Carbon::now((string) ($tenant->run(fn () => Setting::get('app.timezone')) ?: config('app.timezone')))->format('Y-m');
    }

    /** Texting numbers: what the tier includes plus any extra the platform gave this company. */
    public function smsNumbersMax(Tenant $tenant): int
    {
        return $this->tier((int) $tenant->tier)['sms_numbers'] + (int) $tenant->extra_numbers;
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
            'sms' => ['used' => $this->smsUsed($tenant), 'max' => $this->smsLimit($tenant)],
            'numbers' => ['used' => $this->numbersUsed($tenant), 'max' => $this->smsNumbersMax($tenant)],
            'extras' => ['sms' => $this->extraSms($tenant), 'bonus_sms' => $this->bonusSms($tenant), 'numbers' => (int) $tenant->extra_numbers],
        ];
    }
}
