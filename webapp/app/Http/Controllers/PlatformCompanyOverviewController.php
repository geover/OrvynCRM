<?php

namespace App\Http\Controllers;

use App\Jobs\CollectCompanyMetrics;
use App\Models\Automation;
use App\Models\CompanyInvoice;
use App\Models\CompanyManualInvoice;
use App\Models\CompanyUsage;
use App\Models\DataObject;
use App\Models\Tenant;
use App\Models\TenantMetric;
use App\Models\TenantStat;
use App\Services\Tenancy\AccountStanding;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\Limits;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\QueueSnapshot;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\Subscriptions;
use App\Services\Tenancy\UsageTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** One company at a glance: what it holds (contacts, data objects, records, ...), its people and its recent activity. */
class PlatformCompanyOverviewController extends Controller
{
    public function show(Tenant $tenant, Seats $seats, Limits $limits, CompanyServices $services, QueueSnapshot $queue): Response
    {
        $stat = TenantStat::find($tenant->getTenantKey());
        $from = now()->startOfDay()->subDays(29);
        $rows = TenantMetric::where('tenant_id', $tenant->getTenantKey())->where('hour', '>=', $from)->get();
        $days = collect(range(0, 29))->map(fn (int $i) => $from->copy()->addDays($i));
        $byDay = $rows->groupBy(fn (TenantMetric $r) => $r->hour->format('Y-m-d'));
        $series = fn (string $column, int $divide = 1) => $days->map(fn (Carbon $d) => round((float) $byDay->get($d->format('Y-m-d'), collect())->sum($column) / $divide, 1))->all();

        return Inertia::render('Platform/CompanyOverview', [
            'company' => [
                'id' => $tenant->getTenantKey(), 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status, 'plan' => $tenant->plan,
                'created_at' => $tenant->created_at?->toIso8601String(), 'max_users' => (int) $tenant->max_users,
                'max_data_objects' => (int) $tenant->max_data_objects, 'max_automations_per_object' => (int) $tenant->max_automations_per_object,
            ],
            'services' => [
                'catalog' => collect(CompanyServices::CATALOG)->map(fn ($m, $key) => ['key' => $key] + $m)->values(),
                'states' => $services->states($tenant),
                'queued' => $queue->queuedPerCompany()[$tenant->getTenantKey()] ?? 0,
                'object_catalog' => collect(CompanyServices::PER_OBJECT)->map(fn ($key) => ['key' => $key, 'object_text' => CompanyServices::OBJECT_TEXT[$key]] + CompanyServices::CATALOG[$key])->values(),
                'objects' => $tenant->isActive() ? $tenant->run(fn () => $this->objectSwitches($services, $tenant)) : [],
            ],
            'limits' => $tenant->isActive() ? $tenant->run(fn () => ['data_objects' => $limits->dataObjects(), 'objects' => $limits->objects()]) : null,
            'plan' => $this->plan($tenant),
            // where it stands with paying, and what Stripe invoiced it
            'standing' => app(AccountStanding::class)->of($tenant),
            'manual_invoices' => CompanyManualInvoice::where('tenant_id', $tenant->getTenantKey())->orderByDesc('due_on')->limit(30)->get()->map(fn (CompanyManualInvoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'amount' => $i->amount_due, 'currency' => $i->currency, 'issued_on' => $i->issued_on->toDateString(), 'due_on' => $i->due_on->toDateString(),
                'paid_on' => $i->paid_on?->toDateString(), 'state' => $i->state(), 'note' => $i->note,
            ])->values(),
            'invoices' => CompanyInvoice::where('tenant_id', $tenant->getTenantKey())->orderByDesc('issued_at')->limit(12)->get()->map(fn (CompanyInvoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'amount' => $i->amount_due, 'paid' => $i->amount_paid, 'currency' => $i->currency, 'attempts' => $i->attempt_count,
                'issued_at' => $i->issued_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(), 'url' => $i->hosted_url,
            ])->values(),
            'users' => $stat?->users ?? $seats->usedFor($tenant),
            'collected_at' => $stat?->collected_at?->toIso8601String(),
            'counts' => ($stat?->counts ?? []) + ['contacts' => $stat?->contacts ?? 0, 'records' => $stat?->records ?? 0],
            'automations' => ['active' => $stat?->automations_active ?? 0, 'total' => $stat?->automations_total ?? 0],
            'activities_total' => (int) ($stat?->activities_total ?? 0),
            'db_mb' => (float) ($stat?->db_mb ?? 0),
            'breakdown' => $stat?->object_breakdown ?? [],
            'labels' => $days->map(fn (Carbon $d) => $d->format('M j'))->values(),
            'series' => [
                'activities' => $series('activities'), 'new_contacts' => $series('new_contacts'),
                'jobs' => $series('jobs'), 'job_seconds' => $series('job_ms', 1000),
                'automation_runs' => $series('automation_runs'), 'emails_sent' => $series('emails_sent'),
            ],
            'week' => [
                'activities' => (int) $rows->where('hour', '>=', now()->subDays(7))->sum('activities'),
                'new_contacts' => (int) $rows->where('hour', '>=', now()->subDays(7))->sum('new_contacts'),
            ],
        ]);
    }

    /**
     * @return list<array{id: int, name: string, automations: int, services: array<string, array{enabled: bool}>}> every data object with its automation count and the switches of its own services
     */
    private function objectSwitches(CompanyServices $services, Tenant $tenant): array
    {
        $paused = collect(CompanyServices::PER_OBJECT)->mapWithKeys(fn ($key) => [$key => $services->pausedObjects($tenant, $key)]);
        $counts = Automation::whereNotNull('data_object_id')->selectRaw('data_object_id, count(*) as n')->groupBy('data_object_id')->pluck('n', 'data_object_id');

        return DataObject::orderBy('name')->get(['id', 'name'])->map(fn (DataObject $o) => [
            'id' => $o->id, 'name' => $o->name, 'automations' => (int) ($counts[$o->id] ?? 0),
            'services' => $paused->map(fn (array $ids) => ['enabled' => ! in_array($o->id, $ids, true)])->all(),
        ])->all();
    }

    /** Collect this company's numbers now instead of waiting for the next hourly run. */
    public function collect(Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->isActive(), 422, 'The company is suspended.');
        $tenant->run(function () {
            CollectCompanyMetrics::dispatch();
        });

        return back()->with('success', "Collecting {$tenant->name}'s numbers. It takes a moment.");
    }

    /** @return array<string, mixed> the company's tier, trial, usage against the tier, and the billing details it gave */
    private function plan(Tenant $tenant): array
    {
        $plans = app(Plans::class);
        $billing = $plans->billing($tenant);

        return $plans->summary($tenant) + [
            'billing' => $billing?->only(['legal_name', 'contact_name', 'contact_email', 'phone', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'tax_id', 'po_reference']),
            'billing_complete' => (bool) $billing?->isComplete(), 'subscription' => app(Subscriptions::class)->summary($tenant),
            'usage_history' => app(UsageTracker::class)->history($tenant)->map(fn (CompanyUsage $u) => $u->only(['period', 'tier', 'users', 'users_limit', 'sms', 'sms_limit', 'numbers', 'numbers_limit']))->values()->all(),
        ];
    }
}
