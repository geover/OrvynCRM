<?php

namespace App\Services\Tenancy;

use App\Models\CompanyUsage;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Keeps track of how much of its licence tier each company uses: active users, texts sent this month and texting numbers, one
 * record per company per month (so there is a history). When a company reaches 80 % and then 100 % of one of them its
 * administrators are told once; the platform is told once when a company reaches 100 %. Run hourly (`orvyn:track-usage`) and
 * straight away when a company's texts run out.
 */
class UsageTracker
{
    public const NEAR = 80;

    public const AT = 100;

    /** What each resource is called in a notice, and what happens at the limit. */
    private const RESOURCES = [
        'users' => ['active users', 'You cannot add or reactivate people until you free a seat or choose a bigger plan.'],
        'sms' => ['texts this month', 'Texts are not sent until next month, unless you choose a bigger plan.'],
        'numbers' => ['texting numbers', 'You cannot add another texting number unless you choose a bigger plan.'],
    ];

    public function __construct(private readonly Plans $plans, private readonly Seats $seats, private readonly CompanyAdminNotices $notices, private readonly PlatformNotifier $notifier) {}

    /** The month a company is in, in its own time zone (2026-11): a text count starts again on the 1st there. */
    public function periodFor(Tenant $tenant): string
    {
        $tz = (string) ($tenant->run(fn () => Setting::get('app.timezone')) ?: config('app.timezone'));

        return Carbon::now($tz)->format('Y-m');
    }

    /**
     * Measure one company now, update this month's record and send the warnings that are due.
     *
     * @param  list<string>  $reached  filled with "Company: what" for each limit reached for the first time (the platform's digest)
     */
    public function refresh(Tenant $tenant, array &$reached = []): CompanyUsage
    {
        $period = $this->periodFor($tenant);
        $tier = $this->plans->tier((int) $tenant->tier);
        $unlimited = Plans::isUnlimitedSeats((int) $tenant->max_users);
        $measured = [
            'users' => [$this->seats->usedFor($tenant), $unlimited ? null : (int) $tenant->max_users],
            'sms' => [$this->plans->smsUsed($tenant), $this->plans->smsLimit($tenant)],
            'numbers' => [$this->plans->numbersUsed($tenant), $this->plans->smsNumbersMax($tenant)],
        ];

        $row = CompanyUsage::firstOrNew(['tenant_id' => $tenant->getTenantKey(), 'period' => $period]);
        if (! $row->exists) {
            // users and numbers are not monthly: a warning already given stays given. Texts start again.
            $previous = CompanyUsage::where('tenant_id', $tenant->getTenantKey())->where('period', '<', $period)->orderByDesc('period')->first();
            $row->fill(['users_alert' => (int) $previous?->users_alert, 'numbers_alert' => (int) $previous?->numbers_alert, 'sms_alert' => 0]);
        }
        $row->fill([
            'tier' => $tier['tier'], 'users' => max((int) $row->users, $measured['users'][0]), 'users_limit' => $measured['users'][1],
            'sms' => $measured['sms'][0], 'sms_limit' => $measured['sms'][1], 'numbers' => $measured['numbers'][0], 'numbers_limit' => $measured['numbers'][1],
        ]);

        foreach ($measured as $key => [$used, $limit]) {
            if (! $limit) {
                $row->{"{$key}_alert"} = 0;

                continue;
            }
            $stage = $used * 100 >= $limit * self::AT ? self::AT : ($used * 100 >= $limit * self::NEAR ? self::NEAR : 0);
            if ($stage > (int) $row->{"{$key}_alert"}) {
                $this->warn($tenant, $key, $stage, $used, $limit);
                $stage === self::AT && $reached[] = "{$tenant->name}: {$used} of {$limit} ".self::RESOURCES[$key][0];
            }
            $row->{"{$key}_alert"} = $stage;                      // lower again: it can warn again when it climbs
        }
        $row->save();

        return $row;
    }

    /**
     * Measure every active company (the hourly run) and tell the platform once about the companies that reached a limit.
     *
     * @return int how many companies were measured
     */
    public function run(): int
    {
        $reached = [];
        $n = 0;
        foreach (Tenant::active()->get() as $tenant) {
            $this->refresh($tenant, $reached);
            $n++;
        }

        if ($reached) {
            $this->notifier->mail(PlatformNotifier::REQUESTS, count($reached).' '.(count($reached) === 1 ? 'company has' : 'companies have').' reached a limit of its plan', "These companies have used up part of their plan:\n\n- ".implode("\n- ", $reached)."\n\nSee them under Platform > Usage: ".url('/platform/usage'));
        }

        return $n;
    }

    /**
     * The last months of a company, newest first.
     *
     * @return Collection<int, CompanyUsage>
     */
    public function history(Tenant $tenant, int $months = 6): Collection
    {
        return CompanyUsage::where('tenant_id', $tenant->getTenantKey())->orderByDesc('period')->limit($months)->get();
    }

    private function warn(Tenant $tenant, string $key, int $stage, int $used, int $limit): void
    {
        [$what, $consequence] = self::RESOURCES[$key];
        $subject = $stage === self::AT
            ? "{$tenant->name} has reached its limit of {$what}"
            : "{$tenant->name} has used {$stage}% of its {$what}";
        $this->notices->tell($tenant, $subject, [
            $stage === self::AT ? "You have used {$used} of {$limit} {$what}." : "You have used {$used} of {$limit} {$what} ({$stage}%).",
            $stage === self::AT ? $consequence : 'You are getting close to the limit of your plan. You can choose a bigger plan on the Billing & plan page.',
        ]);
    }
}
