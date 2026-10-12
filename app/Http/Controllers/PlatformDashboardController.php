<?php

namespace App\Http\Controllers;

use App\Jobs\CollectCompanyMetrics;
use App\Models\MessageThread;
use App\Models\SeatRequest;
use App\Models\Tenant;
use App\Models\TenantMetric;
use App\Models\TenantStat;
use App\Services\Tenancy\QueueSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform dashboard: what companies do (activity, automations), what their automations weigh, and what that costs
 * the servers and background workers. Reads the hourly metrics (see App\Models\TenantMetric); nothing here opens a company.
 */
class PlatformDashboardController extends Controller
{
    private const RANGES = ['24h' => 24, '7d' => 7, '30d' => 30];

    /** A company using at least this share of all worker time is "heavy", at least half of it "busy". */
    private const HEAVY_SHARE = 0.4;

    private const BUSY_SHARE = 0.2;

    public function index(Request $request, QueueSnapshot $queue): Response
    {
        $range = array_key_exists($request->query('range'), self::RANGES) ? $request->query('range') : '7d';
        $hourly = $range === '24h';
        $from = $hourly ? now()->startOfHour()->subHours(23) : now()->startOfDay()->subDays(self::RANGES[$range] - 1);

        $rows = TenantMetric::where('hour', '>=', $from)->get();
        $buckets = $this->buckets($from, $hourly ? 24 : self::RANGES[$range], $hourly);
        $series = $this->series($rows, $buckets, $hourly);

        $tenants = Tenant::orderBy('name')->get();
        $stats = TenantStat::all()->keyBy('tenant_id');
        $queued = $queue->queuedPerCompany();
        $totalMs = max(1, (int) $rows->sum('job_ms'));
        $perCompany = $rows->groupBy('tenant_id');

        $companies = $tenants->map(function (Tenant $t) use ($perCompany, $stats, $queued, $totalMs, $tenants) {
            $mine = $perCompany->get($t->getTenantKey(), collect());
            $stat = $stats->get($t->getTenantKey());
            $share = $mine->sum('job_ms') / $totalMs;

            return [
                'id' => $t->getTenantKey(), 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status,
                'activities' => (int) $mine->sum('activities'), 'new_contacts' => (int) $mine->sum('new_contacts'),
                'automation_runs' => (int) $mine->sum('automation_runs'), 'automation_runs_failed' => (int) $mine->sum('automation_runs_failed'),
                'emails_sent' => (int) $mine->sum('emails_sent'),
                'jobs' => (int) $mine->sum('jobs'), 'jobs_failed' => (int) $mine->sum('jobs_failed'),
                'job_seconds' => round($mine->sum('job_ms') / 1000, 1), 'automation_seconds' => round($mine->sum('automation_ms') / 1000, 1),
                'message_seconds' => round($mine->sum('message_ms') / 1000, 1),
                'share' => round($share, 4),
                'level' => $tenants->count() > 1 && $share >= self::HEAVY_SHARE ? 'heavy' : ($tenants->count() > 1 && $share >= self::BUSY_SHARE ? 'busy' : 'normal'),
                'queued' => $queued[$t->getTenantKey()] ?? 0,
                'contacts' => $stat?->contacts ?? 0, 'users' => $stat?->users ?? 0, 'max_users' => (int) $t->max_users, 'records' => $stat?->records ?? 0,
                'automations_total' => $stat?->automations_total ?? 0, 'automations_active' => $stat?->automations_active ?? 0,
                'db_mb' => $stat?->db_mb ?? 0.0, 'collected_at' => $stat?->collected_at?->toIso8601String(),
            ];
        })->sortByDesc('activities')->values();

        $jobs = (int) $rows->sum('jobs');

        return Inertia::render('Platform/Dashboard', [
            'range' => $range,
            'labels' => $buckets->map(fn (Carbon $b) => $hourly ? $b->format('H:i') : $b->format('M j'))->values(),
            'series' => $series,
            'kpis' => [
                'companies' => $tenants->count(), 'companies_active' => $tenants->where('status', 'active')->count(),
                'contacts' => (int) $stats->sum('contacts'), 'users' => (int) $stats->sum('users'), 'seats' => (int) $tenants->sum('max_users'),
                'seat_requests' => SeatRequest::pending()->count(),
                'messages_unread' => MessageThread::unreadForPlatform()->count(),
                'companies_full' => $tenants->filter(fn (Tenant $t) => ($stats[$t->getTenantKey()]->users ?? 0) >= $t->max_users)->count(),
                'activities' => (int) $rows->sum('activities'), 'new_contacts' => (int) $rows->sum('new_contacts'),
                'automations_total' => (int) $stats->sum('automations_total'), 'automations_active' => (int) $stats->sum('automations_active'),
                'automation_runs' => (int) $rows->sum('automation_runs'), 'automation_runs_failed' => (int) $rows->sum('automation_runs_failed'),
                'jobs' => $jobs, 'jobs_failed' => (int) $rows->sum('jobs_failed'),
                'job_seconds' => round($rows->sum('job_ms') / 1000, 1),
                'avg_job_ms' => $jobs ? (int) round($rows->sum('job_ms') / $jobs) : 0,
                'automation_share' => round($rows->sum('automation_ms') / $totalMs, 4),
                'message_share' => round($rows->sum('message_ms') / $totalMs, 4),
                'emails_sent' => (int) $rows->sum('emails_sent'),
                'db_mb' => round($stats->sum('db_mb'), 1),
            ],
            'companies' => $companies,
            'top_automations' => $this->topAutomations($stats, $tenants),
            'queue' => $queue->summary(),
            'collected_at' => $stats->max('collected_at')?->toIso8601String(),
            'has_data' => $rows->isNotEmpty() || $stats->whereNotNull('collected_at')->isNotEmpty(),
            'thresholds' => ['heavy' => self::HEAVY_SHARE, 'busy' => self::BUSY_SHARE],
        ]);
    }

    /** Collect the metrics of every active company now instead of waiting for the next hourly run. */
    public function refresh(): RedirectResponse
    {
        $companies = Tenant::active()->get();
        foreach ($companies as $company) {
            $company->run(function () {
                CollectCompanyMetrics::dispatch();
            });
        }

        return back()->with('success', 'Collecting metrics for '.$companies->count().' '.($companies->count() === 1 ? 'company' : 'companies').'. It takes a moment.');
    }

    /** @return Collection<int, Carbon> */
    private function buckets(Carbon $from, int $count, bool $hourly): Collection
    {
        return collect(range(0, $count - 1))->map(fn (int $i) => $hourly ? $from->copy()->addHours($i) : $from->copy()->addDays($i));
    }

    /**
     * One value per bucket for every chart.
     *
     * @param  Collection<int, Carbon>  $buckets
     * @return array<string, list<int|float>>
     */
    private function series(Collection $rows, Collection $buckets, bool $hourly): array
    {
        $key = fn (Carbon $t) => $hourly ? $t->format('Y-m-d H') : $t->format('Y-m-d');
        $byBucket = $rows->groupBy(fn (TenantMetric $r) => $key($r->hour));
        $sum = fn (string $column, ?callable $map = null) => $buckets->map(function (Carbon $b) use ($byBucket, $key, $column, $map) {
            $total = $byBucket->get($key($b), collect())->sum($column);

            return $map ? $map($total) : (int) $total;
        })->values()->all();
        $seconds = fn ($ms) => round($ms / 1000, 1);

        return [
            'activities' => $sum('activities'), 'new_contacts' => $sum('new_contacts'),
            'automation_runs' => $sum('automation_runs'), 'automation_runs_failed' => $sum('automation_runs_failed'),
            'automation_seconds' => $sum('automation_ms', $seconds),
            'jobs' => $sum('jobs'), 'jobs_failed' => $sum('jobs_failed'), 'job_seconds' => $sum('job_ms', $seconds),
            'emails_sent' => $sum('emails_sent'),
        ];
    }

    /**
     * The heaviest automations of the last 24 hours across every company.
     *
     * @return list<array<string, mixed>>
     */
    private function topAutomations(Collection $stats, Collection $tenants): array
    {
        $names = $tenants->mapWithKeys(fn (Tenant $t) => [$t->getTenantKey() => $t->name]);

        return $stats->flatMap(fn (TenantStat $s) => collect($s->top_automations ?? [])->map(fn (array $a) => $a + ['company' => $names[$s->tenant_id] ?? $s->tenant_id]))
            ->sortByDesc('total_ms')->take(8)->values()->all();
    }

    /** The details behind the "Queue right now" number, for its popup. */
    public function queue(QueueSnapshot $queue): JsonResponse
    {
        $details = $queue->details();
        $names = Tenant::pluck('name', 'id');
        $details['companies'] = array_map(fn ($c) => $c + ['name' => $names[$c['id']] ?? 'Deleted company'], $details['companies']);
        $details['recent_failures'] = array_map(fn ($f) => $f + ['company_name' => $f['company'] ? ($names[$f['company']] ?? null) : null], $details['recent_failures']);

        return response()->json($details);
    }
}
