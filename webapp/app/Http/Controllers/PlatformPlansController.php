<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Tenancy\Plans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Platform > Settings > Plans & trial: how long a new sign-up's free trial lasts, and what each licence tier costs. */
class PlatformPlansController extends Controller
{
    public function show(Plans $plans): Response
    {
        $byTier = Tenant::query()->selectRaw('tier, count(*) as n')->groupBy('tier')->pluck('n', 'tier');

        return Inertia::render('Platform/SettingsPlans', [
            'settings' => ['trial_months' => $plans->trialMonths()],
            'tiers' => collect($plans->tiers())->map(fn (array $t) => $t + ['companies' => (int) ($byTier[$t['tier']] ?? 0)])->values(),
            'money' => $plans->money(),
            'trials' => [
                'running' => Tenant::whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now())->count(),
                'ended' => Tenant::whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now())->count(),
            ],
        ]);
    }

    public function save(Request $request, Plans $plans): RedirectResponse
    {
        $d = $request->validate([
            'trial_months' => ['required', 'integer', 'between:1,24'],
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $plans->saveSettings($d);

        return back()->with('success', "Saved. New sign-ups get a trial of {$d['trial_months']} ".($d['trial_months'] === 1 ? 'month' : 'months').'.');
    }
}
