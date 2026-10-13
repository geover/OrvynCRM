<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

/** What the queue looks like right now: depth per queue, failed jobs, and (database queue) waiting jobs per company. */
class QueueSnapshot
{
    /** @return array{driver: string, queues: list<array{name: string, pending: int, running: int}>, failed: int} */
    public function summary(): array
    {
        $connection = config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");
        $queues = [];
        if ($driver === 'database') {
            $conn = config("queue.connections.{$connection}.connection") ?: DB::getDefaultConnection();
            $rows = DB::connection($conn)->table(config("queue.connections.{$connection}.table", 'jobs'))
                ->selectRaw('queue, sum(case when reserved_at is null then 1 else 0 end) as pending, sum(case when reserved_at is null then 0 else 1 end) as running')
                ->groupBy('queue')->get();
            $queues = $rows->map(fn ($r) => ['name' => $r->queue, 'pending' => (int) $r->pending, 'running' => (int) $r->running])->sortBy('name')->values()->all();
        }

        return [
            'driver' => $driver, 'queues' => $queues,
            'failed' => (int) DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->count(),
        ];
    }

    /**
     * Everything behind the "Queue right now" number: per queue, per kind of job, per company, how long the oldest job has
     * waited, and the latest failures. Reads the jobs themselves, so it is only for when somebody asks (database queue
     * only; other drivers cannot be listed).
     *
     * @return array<string, mixed>
     */
    public function details(int $scan = 5000): array
    {
        $summary = $this->summary();
        $connection = config('queue.default');
        $details = $summary + ['supported' => $summary['driver'] === 'database', 'waiting' => 0, 'running' => 0, 'oldest_wait_seconds' => null, 'scanned' => 0, 'truncated' => false, 'kinds' => [], 'companies' => [], 'recent_failures' => []];
        $details['waiting'] = array_sum(array_column($summary['queues'], 'pending'));
        $details['running'] = array_sum(array_column($summary['queues'], 'running'));

        if ($details['supported']) {
            $conn = config("queue.connections.{$connection}.connection") ?: DB::getDefaultConnection();
            $table = DB::connection($conn)->table(config("queue.connections.{$connection}.table", 'jobs'));
            $oldest = (clone $table)->whereNull('reserved_at')->min('available_at');
            $details['oldest_wait_seconds'] = $oldest ? max(0, now()->timestamp - (int) $oldest) : null;

            $kinds = [];
            $companies = [];
            (clone $table)->select('payload', 'queue', 'reserved_at', 'attempts')->orderBy('id')->limit($scan + 1)->get()->each(function ($job, $i) use (&$kinds, &$companies, $scan, &$details) {
                if ($i >= $scan) {
                    $details['truncated'] = true;

                    return;
                }
                $details['scanned']++;
                $payload = json_decode($job->payload, true) ?: [];
                $name = class_basename($payload['displayName'] ?? 'Unknown job');
                $running = $job->reserved_at !== null;
                $key = "{$name}|{$job->queue}";
                $kinds[$key] ??= ['name' => $name, 'queue' => $job->queue, 'waiting' => 0, 'running' => 0, 'retries' => 0];
                $kinds[$key][$running ? 'running' : 'waiting']++;
                $job->attempts > 1 && $kinds[$key]['retries']++;
                if ($id = $payload['tenant_id'] ?? null) {
                    $companies[$id] ??= ['id' => $id, 'waiting' => 0, 'running' => 0];
                    $companies[$id][$running ? 'running' : 'waiting']++;
                }
            });
            $details['kinds'] = collect($kinds)->sortByDesc(fn ($k) => $k['waiting'] + $k['running'])->values()->all();
            $details['companies'] = collect($companies)->sortByDesc(fn ($c) => $c['waiting'] + $c['running'])->values()->all();
        }

        $details['recent_failures'] = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->orderByDesc('id')->limit(10)
            ->get(['id', 'queue', 'payload', 'exception', 'failed_at'])->map(function ($f) {
                $payload = json_decode($f->payload, true) ?: [];

                return [
                    'id' => $f->id, 'queue' => $f->queue, 'job' => class_basename($payload['displayName'] ?? 'Unknown job'), 'company' => $payload['tenant_id'] ?? null,
                    'failed_at' => (string) $f->failed_at, 'error' => str(strtok((string) $f->exception, "\n"))->limit(240)->value(),
                ];
            })->all();

        return $details;
    }

    /**
     * Waiting jobs per company (database queue only; other drivers cannot be grouped by company cheaply).
     *
     * @return array<string, int>
     */
    public function queuedPerCompany(): array
    {
        $connection = config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return [];
        }
        $conn = config("queue.connections.{$connection}.connection") ?: DB::getDefaultConnection();
        $count = [];
        DB::connection($conn)->table(config("queue.connections.{$connection}.table", 'jobs'))->whereNull('reserved_at')->select('payload')->orderBy('id')->limit(20000)
            ->each(function ($job) use (&$count) {
                $id = json_decode($job->payload, true)['tenant_id'] ?? null;
                $id && $count[$id] = ($count[$id] ?? 0) + 1;
            });

        return $count;
    }
}
