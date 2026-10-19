<?php

namespace App\Http\Controllers;

use App\Models\CompanyUsage;
use App\Models\Tenant;
use App\Models\TenantMetric;
use App\Services\Tenancy\UsageTracker;
use Carbon\Carbon;
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
        // the month before (for "more or less than last month"), the last six months of every company (for the detail of a row and the trend), and what
        // each company did in the month besides using its plan: e-mails sent, automation runs, new contacts
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $previous = CompanyUsage::where('period', $start->copy()->subMonth()->format('Y-m'))->get()->keyBy('tenant_id');
        $months = collect(range(5, 0))->map(fn ($i) => $start->copy()->subMonths($i)->format('Y-m'));
        $history = CompanyUsage::whereIn('period', $months)->get()->groupBy('tenant_id');
        $work = TenantMetric::whereBetween('hour', [$start, $start->copy()->endOfMonth()])->selectRaw('tenant_id, sum(emails_sent) as emails, sum(automation_runs) as runs, sum(new_contacts) as contacts')->groupBy('tenant_id')->get()->keyBy('tenant_id');

        $rows = CompanyUsage::where('period', $period)->get()->map(fn (CompanyUsage $u) => [
            'id' => $u->tenant_id, 'name' => $companies[$u->tenant_id] ?? 'Deleted company', 'slug' => $slugs[$u->tenant_id] ?? null, 'status' => $status[$u->tenant_id] ?? 'closed',
            'tier' => $u->tier, 'level' => $u->level(),
            'users' => ['used' => $u->users, 'limit' => $u->users_limit],
            'sms' => ['used' => $u->sms, 'limit' => $u->sms_limit],
            'numbers' => ['used' => $u->numbers, 'limit' => $u->numbers_limit],
            'before' => ($b = $previous[$u->tenant_id] ?? null) ? ['users' => $b->users, 'sms' => $b->sms, 'numbers' => $b->numbers] : null,
            'alerts' => ['users' => $u->users_alert, 'sms' => $u->sms_alert, 'numbers' => $u->numbers_alert],
            'work' => ['emails' => (int) ($work[$u->tenant_id]->emails ?? 0), 'runs' => (int) ($work[$u->tenant_id]->runs ?? 0), 'contacts' => (int) ($work[$u->tenant_id]->contacts ?? 0)],
            'history' => $months->map(fn ($m) => ($h = ($history[$u->tenant_id] ?? collect())->firstWhere('period', $m))
                ? ['period' => $m, 'users' => $h->users, 'sms' => $h->sms, 'numbers' => $h->numbers, 'level' => $h->level()] : ['period' => $m, 'users' => null, 'sms' => null, 'numbers' => null, 'level' => null])->all(),
        ])->sortByDesc(fn ($r) => ['at' => 2, 'near' => 1, 'ok' => 0][$r['level']])->values();

        // the whole platform, month by month
        $trend = $months->map(function (string $m) {
            $all = CompanyUsage::where('period', $m)->get();

            return ['period' => $m, 'companies' => $all->count(), 'users' => (int) $all->sum('users'), 'sms' => (int) $all->sum('sms'), 'numbers' => (int) $all->sum('numbers'),
                'at' => $all->filter(fn (CompanyUsage $u) => $u->level() === 'at')->count(), 'near' => $all->filter(fn (CompanyUsage $u) => $u->level() === 'near')->count()];
        })->values();

        return Inertia::render('Platform/Usage', [
            'period' => $period,
            'periods' => collect([$current])->merge(CompanyUsage::query()->distinct()->orderByDesc('period')->pluck('period'))->unique()->values(),
            'companies' => $rows,
            'trend' => $trend,
            'measured_at' => CompanyUsage::where('period', $period)->max('updated_at'),
        ]);
    }

    /** Measure every company now instead of waiting for the hourly run. */
    public function refresh(UsageTracker $tracker): RedirectResponse
    {
        $n = $tracker->run();

        return back()->with('success', "Measured {$n} ".($n === 1 ? 'company' : 'companies').'.');
    }
}
