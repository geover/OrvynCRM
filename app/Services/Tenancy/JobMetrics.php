<?php

namespace App\Services\Tenancy;

use App\Jobs\EvaluateAutomation;
use App\Jobs\RunAutomationActions;
use App\Jobs\SendEmailMessage;
use App\Jobs\SendSmsMessage;
use App\Jobs\TenantTick;
use App\Models\TenantMetric;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Records what each company's background work costs: how many jobs ran, for how long, how many failed, and how much of
 * that was automations or message sending. Fed by the queue events; the platform dashboard reads it.
 *
 * It never slows or breaks a job: totals are buffered in memory and written in small batches, and any problem
 * while recording is swallowed.
 */
class JobMetrics
{
    private const FLUSH_JOBS = 25;

    private const FLUSH_SECONDS = 20;

    private const AUTOMATION = [EvaluateAutomation::class, RunAutomationActions::class];

    private const MESSAGES = [SendEmailMessage::class, SendSmsMessage::class, 'App\Jobs\DispatchDueMessages', 'App\Jobs\QueueBatchMessages'];

    /** @var array<string, float> start time per running job */
    private array $started = [];

    /** @var array<string, array{tenant: string, hour: Carbon, by: array<string, int>}> */
    private array $buffer = [];

    private int $buffered = 0;

    private float $lastFlush;

    public function __construct()
    {
        $this->lastFlush = microtime(true);
    }

    public function started(JobProcessing $event): void
    {
        $this->started[$this->key($event)] = hrtime(true);
    }

    public function finished(JobProcessed|JobFailed $event): void
    {
        try {
            $key = $this->key($event);
            $ms = isset($this->started[$key]) ? (int) round((hrtime(true) - $this->started[$key]) / 1_000_000) : 0;
            unset($this->started[$key]);

            $payload = $event->job->payload();
            $tenant = $payload['tenant_id'] ?? $this->tickTenant($payload);
            if (! $tenant) {
                return;                                  // central work (housekeeping) belongs to no company
            }

            $name = (string) ($payload['displayName'] ?? '');
            $by = ['jobs' => 1, 'job_ms' => $ms];
            $event instanceof JobFailed && $by['jobs_failed'] = 1;
            if (in_array($name, self::AUTOMATION, true)) {
                $by += ['automation_jobs' => 1, 'automation_ms' => $ms];
            } elseif (in_array($name, self::MESSAGES, true)) {
                $by += ['message_jobs' => 1, 'message_ms' => $ms];
            }

            $this->record((string) $tenant, now()->startOfHour(), $by);
        } catch (Throwable) {
            // metrics must never get in the way of the work they measure
        }
    }

    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];
        $this->buffered = 0;
        $this->lastFlush = microtime(true);

        foreach ($buffer as $row) {
            try {
                TenantMetric::add($row['tenant'], $row['hour'], $row['by']);
            } catch (Throwable) {
                // lost counters are better than a failed job
            }
        }
    }

    public function stopping(WorkerStopping $event): void
    {
        $this->flush();
    }

    /** @param  array<string, int>  $by */
    private function record(string $tenant, Carbon $hour, array $by): void
    {
        $slot = $tenant.'|'.$hour->timestamp;
        $this->buffer[$slot] ??= ['tenant' => $tenant, 'hour' => $hour, 'by' => []];
        foreach ($by as $column => $n) {
            $this->buffer[$slot]['by'][$column] = ($this->buffer[$slot]['by'][$column] ?? 0) + $n;
        }

        if (++$this->buffered >= self::FLUSH_JOBS || microtime(true) - $this->lastFlush >= self::FLUSH_SECONDS || app()->runningUnitTests()) {
            $this->flush();
        }
    }

    private function key(JobProcessing|JobProcessed|JobFailed $event): string
    {
        return $event->connectionName.'|'.($event->job->uuid() ?: $event->job->getJobId());
    }

    /** The company's scheduler tick is dispatched from the central scheduler, so its payload names no company: read it from the job. */
    private function tickTenant(array $payload): ?string
    {
        if (($payload['displayName'] ?? null) !== TenantTick::class) {
            return null;
        }
        $command = $payload['data']['command'] ?? null;
        $tick = is_string($command) ? @unserialize($command, ['allowed_classes' => [TenantTick::class]]) : null;

        return $tick instanceof TenantTick ? $tick->tenantId : null;
    }
}
