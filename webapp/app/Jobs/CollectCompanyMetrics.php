<?php

namespace App\Jobs;

use App\Models\TenantMetric;
use App\Models\TenantStat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Hourly, inside one company: counts what happened in the last two hours (so the hour just ended is final) and takes a
 * snapshot of the company's totals, for the platform dashboard. Cheap by design: indexed counts and one size lookup.
 */
class CollectCompanyMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $tenantId = (string) tenant('id');
        if ($tenantId === '') {
            return;
        }

        foreach ([now()->startOfHour()->subHour(), now()->startOfHour()] as $from) {
            $to = $from->copy()->addHour();
            $between = fn ($q) => $q->where('created_at', '>=', $from)->where('created_at', '<', $to);
            TenantMetric::put($tenantId, $from, [
                'activities' => $between(DB::table('activities'))->count(),
                'new_contacts' => $between(DB::table('contacts'))->count(),
                'automation_runs' => $between(DB::table('automation_runs'))->count(),
                'automation_runs_failed' => $between(DB::table('automation_runs')->where('status', 'failed'))->count(),
                'emails_sent' => DB::table('email_messages')->where('status', 'sent')->where('updated_at', '>=', $from)->where('updated_at', '<', $to)->count(),
            ]);
        }

        TenantStat::updateOrCreate(['tenant_id' => $tenantId], [
            'contacts' => DB::table('contacts')->whereNull('deleted_at')->count(),
            'users' => DB::table('users')->where('is_platform_support', false)->count(),
            'records' => DB::table('data_object_records')->whereNull('deleted_at')->count(),
            'automations_total' => DB::table('automations')->count(),
            'automations_active' => DB::table('automations')->where('status', 'active')->count(),
            'activities_total' => (int) DB::table('activities')->max('id'),            // ids only grow: as good as a count, and instant
            'db_mb' => $this->databaseMegabytes(),
            'top_automations' => $this->heaviestAutomations(),
            'counts' => $this->counts(),
            'object_breakdown' => $this->objectBreakdown(),
            'collected_at' => now(),
        ]);
    }

    /**
     * What the company holds, for the platform's company overview. Plain counts on indexed tables; soft-deleted rows are left out.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $count = function (string $table, ?callable $where = null): int {
            $q = DB::table($table);
            Schema::hasColumn($table, 'deleted_at') && $q->whereNull('deleted_at');
            $where && $where($q);

            return Schema::hasTable($table) ? (int) $q->count() : 0;
        };

        return [
            'contacts' => $count('contacts'),
            'data_objects' => $count('data_objects'),
            'records' => $count('data_object_records'),
            'tasks_open' => $count('tasks', fn ($q) => $q->whereNull('completed_at')),
            'tasks_done' => $count('tasks', fn ($q) => $q->whereNotNull('completed_at')),
            'notes' => $count('notes'),
            'tags' => $count('tags'),
            'forms' => $count('forms'),
            'submissions' => $count('form_submissions'),
            'emails' => $count('email_messages'),
            'sms' => $count('sms_messages'),
            'saved_queries' => $count('saved_queries'),
            'widgets' => $count('widgets'),
            'webhooks' => $count('webhook_endpoints'),
            'price_lists' => $count('price_lists'),
            'attachments' => $count('contact_attachments') + $count('data_object_attachments'),
        ];
    }

    /**
     * Records and automations per data object (the biggest first).
     *
     * @return list<array{name: string, records: int, automations: int}>
     */
    private function objectBreakdown(): array
    {
        $per = DB::table('data_object_records')->whereNull('deleted_at')->selectRaw('data_object_id, count(*) as n')->groupBy('data_object_id')->pluck('n', 'data_object_id');

        $automations = DB::table('automations')->whereNotNull('data_object_id')->selectRaw('data_object_id, count(*) as n')->groupBy('data_object_id')->pluck('n', 'data_object_id');

        return DB::table('data_objects')->pluck('name', 'id')->map(fn ($name, $id) => ['name' => $name, 'records' => (int) ($per[$id] ?? 0), 'automations' => (int) ($automations[$id] ?? 0)])
            ->sortByDesc('records')->take(20)->values()->all();
    }

    /** The heaviest automations of the last 24 hours: runs, failures and how long a run takes (created to finished). */
    private function heaviestAutomations(): array
    {
        $runs = DB::table('automation_runs')->where('created_at', '>=', now()->subDay())->orderByDesc('id')->limit(5000)
            ->get(['automation_id', 'status', 'created_at', 'updated_at']);

        $by = [];
        foreach ($runs as $run) {
            $row = &$by[$run->automation_id];
            $row ??= ['runs' => 0, 'failed' => 0, 'ms' => 0];
            $row['runs']++;
            $run->status === 'failed' && $row['failed']++;
            $row['ms'] += max(0, Carbon::parse($run->updated_at)->diffInMilliseconds(Carbon::parse($run->created_at), true));
            unset($row);
        }
        uasort($by, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $top = array_slice($by, 0, 5, true);
        $names = DB::table('automations')->whereIn('id', array_keys($top))->pluck('name', 'id');

        return collect($top)->map(fn ($r, $id) => [
            'id' => $id, 'name' => $names[$id] ?? "Automation #{$id}", 'runs' => $r['runs'], 'failed' => $r['failed'],
            'total_ms' => (int) $r['ms'], 'avg_ms' => (int) round($r['ms'] / max(1, $r['runs'])),
        ])->values()->all();
    }

    private function databaseMegabytes(): float
    {
        try {
            $conn = DB::connection();
            if (in_array($conn->getDriverName(), ['mysql', 'mariadb'], true)) {
                $bytes = (float) $conn->selectOne('select coalesce(sum(data_length + index_length), 0) as b from information_schema.tables where table_schema = ?', [$conn->getDatabaseName()])->b;
            } else {
                $path = (string) $conn->getDatabaseName();
                $bytes = $path !== '' && File::exists($path) ? (float) File::size($path) : 0.0;
            }

            return round($bytes / 1048576, 2);
        } catch (Throwable) {
            return 0.0;
        }
    }
}
