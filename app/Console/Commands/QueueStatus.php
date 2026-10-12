<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Queue health at a glance: depth and oldest wait per queue, failed jobs, and which companies are filling the queue.
 * Exit code 1 when a limit is exceeded, so cron / a monitoring agent can alert on it:
 *
 *   * * * * * php artisan orvyn:queue-status --quiet-ok || notify-the-team
 */
class QueueStatus extends Command
{
    protected $signature = 'orvyn:queue-status
        {--alert-over=2000 : pending jobs in one queue above which the status is "alert"}
        {--oldest-over=300 : seconds the oldest pending job may wait before the status is "alert"}
        {--tenants : (database queue) list the companies with the most pending jobs}
        {--quiet-ok : print nothing unless there is an alert}';

    protected $description = 'Show queue depth, oldest wait, failed jobs and the busiest companies';

    private const QUEUES = ['ticks', 'automations', 'messages', 'webhooks', 'sync', 'imports', 'default'];

    public function handle(): int
    {
        $rows = DB::getDefaultConnection() && config('queue.default') !== 'sync' && config('queue.connections.'.config('queue.default').'.driver') === 'database'
            ? $this->fromDatabase()
            : $this->fromDriver();

        $failed = (int) DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->count();
        $alerts = [];
        foreach ($rows as $queue => $r) {
            ($r['pending'] > (int) $this->option('alert-over')) && $alerts[] = "{$queue}: {$r['pending']} pending";
            ($r['oldest'] !== null && $r['oldest'] > (int) $this->option('oldest-over')) && $alerts[] = "{$queue}: oldest job has waited {$r['oldest']}s";
        }

        if (! ($this->option('quiet-ok') && ! $alerts)) {
            $this->table(['queue', 'pending', 'running', 'oldest wait (s)'], collect($rows)->map(fn ($r, $q) => [$q, $r['pending'], $r['running'] ?? '-', $r['oldest'] ?? '-'])->values()->all());
            $this->line("Failed jobs: {$failed}");
            $this->option('tenants') && $this->busiestCompanies();
            foreach ($alerts as $a) {
                $this->error("ALERT {$a}");
            }
            $alerts || $this->info('OK');
        }

        return $alerts ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, array{pending: int, running: int, oldest: ?int}> */
    private function fromDatabase(): array
    {
        $conn = config('queue.connections.'.config('queue.default').'.connection') ?: DB::getDefaultConnection();
        $table = config('queue.connections.'.config('queue.default').'.table', 'jobs');
        $now = now()->getTimestamp();
        $out = [];
        foreach (self::QUEUES as $q) {
            $base = DB::connection($conn)->table($table)->where('queue', $q);
            $out[$q] = [
                'pending' => (clone $base)->whereNull('reserved_at')->count(),
                'running' => (clone $base)->whereNotNull('reserved_at')->count(),
                'oldest' => ($min = (clone $base)->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at')) ? $now - (int) $min : null,
            ];
        }

        return $out;
    }

    private function fromDriver(): array
    {
        $out = [];
        foreach (self::QUEUES as $q) {
            $out[$q] = ['pending' => (int) Queue::size($q), 'running' => 0, 'oldest' => null];
        }

        return $out;
    }

    private function busiestCompanies(): void
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'database') {
            $this->warn('--tenants needs the database queue driver; with Redis use Horizon tags or one queue per tier.');

            return;
        }
        $conn = config('queue.connections.'.config('queue.default').'.connection') ?: DB::getDefaultConnection();
        $count = [];
        DB::connection($conn)->table('jobs')->whereNull('reserved_at')->select('payload')->limit(50000)->orderBy('id')->each(function ($job) use (&$count) {
            $id = json_decode($job->payload, true)['tenant_id'] ?? '(central)';
            $count[$id] = ($count[$id] ?? 0) + 1;
        });
        arsort($count);
        $names = Tenant::whereIn('id', array_keys($count))->pluck('name', 'id');
        $this->table(['company', 'pending jobs'], collect(array_slice($count, 0, 15, true))->map(fn ($n, $id) => [$names[$id] ?? $id, $n])->values()->all());
    }
}
