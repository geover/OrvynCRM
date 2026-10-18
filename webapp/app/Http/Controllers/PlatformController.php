<?php

namespace App\Http\Controllers;

use App\Jobs\CollectCompanyMetrics;
use App\Models\CompanyBilling;
use App\Models\CompanySubscription;
use App\Models\CompanyUsage;
use App\Models\SeatRequest;
use App\Models\Tenant;
use App\Models\TenantStat;
use App\Services\Tenancy\CompanyAdminNotices;
use App\Services\Tenancy\CompanySession;
use App\Services\Tenancy\InactiveCompanies;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\Subscriptions;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** The platform area: create, suspend and open companies. Only platform administrators reach it (EnsurePlatformAdmin). */
class PlatformController extends Controller
{
    public function index(Request $request, CompanySession $session, Seats $seats, InactiveCompanies $inactive, Plans $plans): Response
    {
        $session->leaveCurrentCompany($request);        // arriving from "support mode": close the company we were looking at
        $stats = TenantStat::pluck('users', 'tenant_id');
        $overview = TenantStat::all()->keyBy('tenant_id');
        $billed = CompanyBilling::whereNotNull('completed_at')->pluck('tenant_id')->flip();
        $paying = CompanySubscription::whereIn('status', CompanySubscription::GOOD)->pluck('tenant_id')->flip();
        $subs = CompanySubscription::pluck('status', 'tenant_id');
        $usage = CompanyUsage::whereIn('period', [now()->format('Y-m'), now()->subMonth()->format('Y-m')])->orderBy('period')->get()->keyBy('tenant_id');
        // waiting requests per company: for users (the Users column) and for everything else, i.e. data objects, automations, texts (the Data column)
        $pending = SeatRequest::pending()->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $pendingUsers = SeatRequest::pending()->where('kind', SeatRequest::USERS)->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');

        return Inertia::render('Platform/Companies', [
            'companies' => Tenant::withCount('members')->orderBy('name')->get()->map(fn (Tenant $t) => [
                'id' => $t->getTenantKey(), 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status, 'plan' => $t->plan, 'database' => $t->database()->getName(),
                'members' => $t->members_count, 'created_at' => $t->created_at?->toIso8601String(),
                'users' => (int) ($stats[$t->getTenantKey()] ?? $seats->usedFor($t)), 'max_users' => (int) $t->max_users,
                'max_data_objects' => (int) $t->max_data_objects, 'max_automations_per_object' => (int) $t->max_automations_per_object,
                'pending_requests' => (int) ($pending[$t->getTenantKey()] ?? 0),
                'pending_user_requests' => (int) ($pendingUsers[$t->getTenantKey()] ?? 0),
                'pending_other_requests' => (int) ($pending[$t->getTenantKey()] ?? 0) - (int) ($pendingUsers[$t->getTenantKey()] ?? 0),
                // the licence tier and where the free trial stands
                'tier' => (int) $t->tier, 'extra_sms' => (int) $t->extra_sms, 'bonus_sms' => $plans->bonusSms($t), 'extra_numbers' => (int) $t->extra_numbers, 'trial' => $plans->state($t, $billed->has($t->getTenantKey()), $paying->has($t->getTenantKey())), 'subscription' => $subs[$t->getTenantKey()] ?? null, 'usage_level' => ($usage[$t->getTenantKey()] ?? null)?->level() ?? 'ok',
                // when somebody last used it, and whether the daily check has flagged it as abandoned
                'last_active_at' => ($t->last_active_at ?? $t->created_at)?->toIso8601String(), 'idle_days' => $t->isActive() ? $inactive->daysIdle($t) : null,
                'inactive' => $t->isActive() && $t->inactive_since !== null, 'status_note' => $t->status_note, 'closed_at' => $t->closed_at?->toIso8601String(),
                ...$this->headlineCounts($t, $overview[$t->getTenantKey()] ?? null),
            ])->values(),
            'email' => $request->attributes->get('platform_identity')->email,
            'inactivity' => ['enabled' => $inactive->enabled(), 'days' => $inactive->days()],
            'tiers' => $plans->tiers(), 'trial_months' => $plans->trialMonths(),
        ]);
    }

    /**
     * Contacts, data objects and records for the companies list. They come from the hourly totals; a company whose totals
     * are missing or from before these counts existed is counted live right now (three cheap queries) and queued for a
     * proper collection, so the list is never left blank.
     *
     * @return array{contacts: int|null, data_objects: int|null, records: int|null}
     */
    private function headlineCounts(Tenant $t, ?TenantStat $stat): array
    {
        $counted = $stat?->collected_at !== null;
        if ($counted && isset($stat->counts['data_objects'])) {
            return ['contacts' => $stat->contacts, 'data_objects' => (int) $stat->counts['data_objects'], 'records' => $stat->records];
        }
        if (! $t->isActive()) {
            return ['contacts' => $counted ? $stat->contacts : null, 'data_objects' => null, 'records' => $counted ? $stat->records : null];
        }

        if (Cache::add("collect-requested:{$t->getTenantKey()}", 1, 300)) {
            $t->run(function () {
                CollectCompanyMetrics::dispatch();
            });
        }

        return $t->run(fn () => [
            'contacts' => DB::table('contacts')->whereNull('deleted_at')->count(),
            'data_objects' => DB::table('data_objects')->count(),
            'records' => DB::table('data_object_records')->whereNull('deleted_at')->count(),
        ]);
    }

    public function store(Request $request, TenantProvisioner $provisioner, Plans $plans): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email'],
            'owner_password' => ['nullable', Password::min(10)->mixedCase()->numbers()],
            'max_users' => ['nullable', 'integer', 'min:'.$plans->seatsFor((int) ($request->input('tier') ?: config('plans.trial_tier'))), 'max:1000000'],
            'max_data_objects' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_automations_per_object' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'tier' => ['nullable', 'integer', 'between:1,'.count(config('plans.tiers'))],
            'trial' => ['sometimes', 'boolean'],
        ], ['max_users.min' => 'This tier includes at least :min users. You can raise the limit above that, but not set it lower.']);

        $tenant = $provisioner->createCompany($d['name'], $d['slug'] ?? null, [
            'name' => $d['owner_name'], 'email' => $d['owner_email'], 'password' => $d['owner_password'] ?? null,
        ], maxUsers: $d['max_users'] ?? null, maxDataObjects: $d['max_data_objects'] ?? null, maxAutomationsPerObject: $d['max_automations_per_object'] ?? null, tier: $d['tier'] ?? null, trial: (bool) ($d['trial'] ?? false));
        if (empty($d['owner_password'])) {
            \Illuminate\Support\Facades\Password::sendResetLink(['email' => $d['owner_email']]);      // the owner sets their own password
        }

        return back()->with('success', "{$tenant->name} was created.");
    }

    public function update(Request $request, Tenant $tenant, Plans $plans, Subscriptions $subscriptions, CompanyAdminNotices $notices): RedirectResponse
    {
        $d = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'closed'])],
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(TenantProvisioner::RESERVED), Rule::unique('tenants', 'slug')->ignore($tenant->getTenantKey(), 'id')],
            'plan' => ['sometimes', 'nullable', 'string', 'max:40'],
            'max_users' => ['sometimes', 'integer', 'min:'.$plans->seatsFor((int) ($request->input('tier') ?: $tenant->tier)), 'max:1000000'],
            'extra_sms' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'bonus_sms' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'extra_numbers' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'max_data_objects' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'max_automations_per_object' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'tier' => ['sometimes', 'integer', 'between:1,'.count(config('plans.tiers'))],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'tier_billing' => ['sometimes', 'nullable', Rule::in(['stripe_now', 'stripe_renewal', 'app_only'])],
            'tier_tell' => ['sometimes', 'boolean'],
        ], ['max_users.min' => 'This tier includes at least :min users. You can raise the limit above that, but not set it lower.', 'slug.unique' => 'That slug is already taken.', 'slug.not_in' => 'That slug is reserved.', 'slug.regex' => 'Use lowercase letters, numbers and single hyphens.']);
        // a new tier brings its user limit (an explicit limit sent with it wins, but never below the tier's); a trial end of null takes the trial away
        $newTier = $d['tier'] ?? null;
        $billing = $d['tier_billing'] ?? null;
        $tell = (bool) ($d['tier_tell'] ?? true);
        unset($d['tier'], $d['tier_billing'], $d['tier_tell']);
        $tierChanged = $newTier !== null && (int) $newTier !== (int) $tenant->tier;
        $oldTier = $plans->tier((int) $tenant->tier);

        // a company that pays through a running Stripe subscription: the subscription is moved FIRST, so a refusal from Stripe changes nothing here
        $running = $tierChanged && $subscriptions->isRunning($tenant);
        if ($running) {
            if ($billing === null) {
                throw ValidationException::withMessages(['tier_billing' => 'This company pays through Stripe: choose how its subscription follows the change.']);
            }
            if ($billing !== 'app_only') {
                try {
                    $subscriptions->changeTier($tenant, (int) $newTier, $billing === 'stripe_now' ? 'create_prorations' : 'none');
                } catch (Throwable $e) {
                    $why = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();

                    throw ValidationException::withMessages(['tier_billing' => 'Stripe did not change the subscription ('.str($why)->squish()->limit(160)->value().'). Nothing was changed.']);
                }
            }
        }
        if (array_key_exists('trial_ends_at', $d)) {
            $d += ['trial_notice_stage' => 0];
        }
        // brought back (or never left): a fresh clock, so the daily check does not flag it again at once
        if (($d['status'] ?? null) === 'active') {
            $d += ['last_active_at' => now(), 'inactive_since' => null, 'closed_at' => null, 'status_note' => null];
        } elseif (($d['status'] ?? null) === 'closed') {
            $d += ['closed_at' => now(), 'inactive_since' => null];
        } elseif (($d['status'] ?? null) === 'suspended') {
            $d += ['inactive_since' => null];
        }
        // extra texts for this month only: a changed number counts for the current month (and ends with it); an unchanged one keeps its month
        if (array_key_exists('bonus_sms', $d)) {
            if ((int) $d['bonus_sms'] === $plans->bonusSms($tenant)) {
                unset($d['bonus_sms']);
            } else {
                $d['bonus_sms_month'] = (int) $d['bonus_sms'] > 0 ? $plans->currentMonth($tenant) : null;
            }
        }
        $tenant->update($d);
        if ($newTier !== null) {
            $plans->applyTier($tenant, (int) $newTier);
            isset($d['max_users']) && $tenant->update(['max_users' => $d['max_users']]);
        }
        if ($tierChanged) {
            $running && $billing === 'app_only' && $subscriptions->keepTierDespiteStripe($tenant);
            $tell && $this->tellTierChange($notices, $plans, $tenant->fresh(), $oldTier, $running ? $billing : null);
        }
        array_key_exists('extra_numbers', $d) && $plans->enforceNumbers($tenant->fresh());

        return back()->with('success', 'Saved.');
    }

    /** @param  array<string, mixed>  $from  the tier it was on */
    private function tellTierChange(CompanyAdminNotices $notices, Plans $plans, Tenant $tenant, array $from, ?string $billing): void
    {
        $to = $plans->tier((int) $tenant->tier);
        $lines = ["The platform moved your company from {$from['name']} to {$to['name']}.", 'Now included: '.($to['users'] === null ? 'unlimited users' : "{$to['users']} active users").", {$to['sms_per_month']} texts a month and {$to['sms_numbers']} texting numbers."];
        $lines[] = match ($billing) {
            'stripe_now' => 'Your subscription was moved to the new plan. Stripe charges or credits the difference for the rest of this period.',
            'stripe_renewal' => 'Your subscription was moved to the new plan from your next renewal. Nothing is charged or credited now.',
            'app_only' => 'Your subscription and card are unchanged: the new plan was given by the platform.',
            default => 'You can see your plan and its usage on the Billing & plan page.',
        };
        $notices->tell($tenant, "Your plan is now {$to['name']}", $lines);
    }

    /** Support access: open the company as an administrator (the person gets a profile there on first use). */
    public function open(Request $request, Tenant $tenant, CompanySession $session): RedirectResponse
    {
        return $session->enter($request, $request->attributes->get('platform_identity'), $tenant);
    }
}
