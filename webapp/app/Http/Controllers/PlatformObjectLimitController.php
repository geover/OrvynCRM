<?php

namespace App\Http\Controllers;

use App\Models\DataObject;
use App\Models\ObjectAutomationLimit;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The platform sets how many automations ONE data object of a company may have (replacing the company-wide default), or clears it. */
class PlatformObjectLimitController extends Controller
{
    public function update(Request $request, Tenant $tenant, int $object): RedirectResponse
    {
        $d = $request->validate(['max_automations' => ['required', 'integer', 'min:1', 'max:100000']]);
        abort_unless($tenant->run(fn () => DataObject::whereKey($object)->exists()), 404);
        ObjectAutomationLimit::updateOrCreate(['tenant_id' => $tenant->getTenantKey(), 'data_object_id' => $object], ['max_automations' => $d['max_automations']]);

        return back()->with('success', 'Saved.');
    }

    public function destroy(Tenant $tenant, int $object): RedirectResponse
    {
        ObjectAutomationLimit::where('tenant_id', $tenant->getTenantKey())->where('data_object_id', $object)->delete();

        return back()->with('success', 'Back to the company default.');
    }
}
