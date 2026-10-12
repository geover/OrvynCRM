<?php

namespace App\Http\Controllers;

use App\Jobs\TenantTick;
use App\Models\DataObject;
use App\Models\Tenant;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\QueueSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Platform control of every company's background services: see what runs, switch it on or off, run it now. */
class PlatformServicesController extends Controller
{
    public function index(CompanyServices $services, QueueSnapshot $queue): Response
    {
        $queued = $queue->queuedPerCompany();

        return Inertia::render('Platform/Services', [
            'catalog' => collect(CompanyServices::CATALOG)->map(fn ($m, $key) => ['key' => $key] + $m)->values(),
            'companies' => Tenant::orderBy('name')->get()->map(fn (Tenant $t) => [
                'id' => $t->getTenantKey(), 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status,
                'services' => $services->states($t), 'queued' => $queued[$t->getTenantKey()] ?? 0,
            ])->values(),
            'queue' => $queue->summary(),
        ]);
    }

    /** Switch one service (or all) for one company (or all companies). */
    public function update(Request $request, CompanyServices $services): RedirectResponse
    {
        $d = $request->validate([
            'company' => ['required', 'string'],
            'service' => ['required', 'string', Rule::in([...array_keys(CompanyServices::CATALOG), '*'])],
            'enabled' => ['required', 'boolean'],
        ]);

        $companies = $d['company'] === '*' ? Tenant::pluck('id') : Tenant::whereKey($d['company'])->pluck('id');
        abort_if($companies->isEmpty(), 404);
        $keys = $d['service'] === '*' ? array_keys(CompanyServices::CATALOG) : [$d['service']];
        foreach ($companies as $id) {
            foreach ($keys as $key) {
                $services->set($id, $key, (bool) $d['enabled']);
            }
        }

        return back()->with('success', $d['enabled'] ? 'Switched on.' : 'Switched off.');
    }

    /** Switch one service for one data object of one company (today only automations). */
    public function updateObject(Request $request, Tenant $tenant, int $object, CompanyServices $services): RedirectResponse
    {
        $d = $request->validate(['service' => ['required', Rule::in(CompanyServices::PER_OBJECT)], 'enabled' => ['required', 'boolean']]);
        abort_unless($tenant->run(fn () => DataObject::whereKey($object)->exists()), 404);
        $services->setObject($tenant, $d['service'], $object, (bool) $d['enabled']);

        return back()->with('success', $d['enabled'] ? 'Switched on.' : 'Switched off.');
    }

    /** Do this company's scheduled work now instead of waiting for the next minute. */
    public function run(Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->isActive(), 422, 'The company is suspended.');
        TenantTick::dispatch($tenant->getTenantKey(), now()->toIso8601String());

        return back()->with('success', "Queued a run for {$tenant->name}.");
    }
}
