<?php

namespace App\Http\Controllers;

use App\Jobs\CollectCompanyMetrics;
use App\Models\SeatRequest;
use App\Models\Tenant;
use App\Models\TenantStat;
use App\Services\Tenancy\CompanySession;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** The platform area: create, suspend and open companies. Only platform administrators reach it (EnsurePlatformAdmin). */
class PlatformController extends Controller
{
    public function index(Request $request, CompanySession $session, Seats $seats): Response
    {
        $session->leaveCurrentCompany($request);        // arriving from "support mode": close the company we were looking at
        $stats = TenantStat::pluck('users', 'tenant_id');
        $overview = TenantStat::all()->keyBy('tenant_id');
        $pending = SeatRequest::pending()->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');

        return Inertia::render('Platform/Companies', [
            'companies' => Tenant::withCount('members')->orderBy('name')->get()->map(fn (Tenant $t) => [
                'id' => $t->getTenantKey(), 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status, 'plan' => $t->plan,
                'members' => $t->members_count, 'created_at' => $t->created_at?->toIso8601String(),
                'users' => (int) ($stats[$t->getTenantKey()] ?? $seats->usedFor($t)), 'max_users' => (int) $t->max_users,
                'max_data_objects' => (int) $t->max_data_objects, 'max_automations_per_object' => (int) $t->max_automations_per_object,
                'pending_requests' => (int) ($pending[$t->getTenantKey()] ?? 0),
                ...$this->headlineCounts($t, $overview[$t->getTenantKey()] ?? null),
            ])->values(),
            'email' => $request->attributes->get('platform_identity')->email,
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

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email'],
            'owner_password' => ['nullable', Password::min(10)->mixedCase()->numbers()],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_data_objects' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_automations_per_object' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $tenant = $provisioner->createCompany($d['name'], $d['slug'] ?? null, [
            'name' => $d['owner_name'], 'email' => $d['owner_email'], 'password' => $d['owner_password'] ?? null,
        ], maxUsers: $d['max_users'] ?? null, maxDataObjects: $d['max_data_objects'] ?? null, maxAutomationsPerObject: $d['max_automations_per_object'] ?? null);
        if (empty($d['owner_password'])) {
            \Illuminate\Support\Facades\Password::sendResetLink(['email' => $d['owner_email']]);      // the owner sets their own password
        }

        return back()->with('success', "{$tenant->name} was created.");
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $d = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'name' => ['sometimes', 'string', 'max:120'],
            'plan' => ['sometimes', 'nullable', 'string', 'max:40'],
            'max_users' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'max_data_objects' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'max_automations_per_object' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $tenant->update($d);

        return back()->with('success', 'Saved.');
    }

    /** Support access: open the company as an administrator (the person gets a profile there on first use). */
    public function open(Request $request, Tenant $tenant, CompanySession $session): RedirectResponse
    {
        return $session->enter($request, $request->attributes->get('platform_identity'), $tenant);
    }
}
