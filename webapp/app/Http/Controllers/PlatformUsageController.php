<?php

namespace App\Http\Controllers;

use App\Models\CompanyUsage;
use App\Models\Tenant;
use App\Services\Tenancy\UsageTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Platform > Usage: every company against what its tier allows (active users, texts this month, texting numbers), month by month. */
class PlatformUsageController extends Controller
{
    public function index(Request $request, UsageTracker $tracker): Response
    {
        $current = now()->format('Y-m');
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')) ? (string) $request->query('period') : $current;

        // this month's records of any active company that has none yet (a new company, or the first visit after installing)
        if ($period === $current) {
            $have = CompanyUsage::where('period', $current)->pluck('tenant_id')->all();
            Tenant::active()->whereNotIn('id', $have)->get()->each(fn (Tenant $t) => $tracker->refresh($t));
        }

        $companies = Tenant::pluck('name', 'id');
        $slugs = Tenant::pluck('slug', 'id');
        $status = Tenant::pluck('status', 'id');
        $rows = CompanyUsage::where('period', $period)->get()->map(fn (CompanyUsage $u) => [
            'id' => $u->tenant_id, 'name' => $companies[$u->tenant_id] ?? 'Deleted company', 'slug' => $slugs[$u->tenant_id] ?? null, 'status' => $status[$u->tenant_id] ?? 'closed',
            'tier' => $u->tier, 'level' => $u->level(),
            'users' => ['used' => $u->users, 'limit' => $u->users_limit],
            'sms' => ['used' => $u->sms, 'limit' => $u->sms_limit],
            'numbers' => ['used' => $u->numbers, 'limit' => $u->numbers_limit],
        ])->sortByDesc(fn ($r) => ['at' => 2, 'near' => 1, 'ok' => 0][$r['level']])->values();

        return Inertia::render('Platform/Usage', [
            'period' => $period,
            'periods' => collect([$current])->merge(CompanyUsage::query()->distinct()->orderByDesc('period')->pluck('period'))->unique()->values(),
            'companies' => $rows,
        ]);
    }

    /** Measure every company now instead of waiting for the hourly run. */
    public function refresh(UsageTracker $tracker): RedirectResponse
    {
        $n = $tracker->run();

        return back()->with('success', "Measured {$n} ".($n === 1 ? 'company' : 'companies').'.');
    }
}
