<?php

namespace App\Services;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

/** Remembers when each scheduled job last ran and how it went, and lists the schedule with the next run of each. */
class ScheduleLog
{
    /** What each job does, in plain words, by its name or command. */
    private const LABELS = [
        'heartbeat' => 'Proof of life (scheduler and worker)',
        'companies:tick' => 'Hand every company its due work (messages, automations, syncs)',
        'companies:inactivity' => 'Look for abandoned companies',
        'companies:usage' => 'Measure each company against its plan',
        'companies:payment-followups' => 'Remind companies whose payment failed',
        'companies:trial-reminders' => 'Remind companies their free trial is running out',
        'queue:prune-batches' => 'Clean up old job batches',
        'queue:prune-failed' => 'Clean up old failed jobs',
        'sanctum:prune-expired' => 'Clean up expired API tokens',
        'metrics:prune' => 'Clean up dashboard figures older than 90 days',
    ];

    public function finished(ScheduledTaskFinished $e): void
    {
        $this->store($e->task, ($e->task->exitCode ?? 0) === 0, round($e->runtime, 2), null);
    }

    public function failed(ScheduledTaskFailed $e): void
    {
        $this->store($e->task, false, null, str($e->exception->getMessage())->squish()->limit(200)->value());
    }

    /**
     * @return list<array{job: string|null, label: string, expression: string, last_run: string|null, seconds: float|null, ok: bool|null, error: string|null, next_run: string}>
     */
    public function rows(): array
    {
        // a web request never loads routes/console.php, where the schedule is defined: load it (once) so the list is the real one
        app(ConsoleKernel::class)->bootstrap();

        return collect(app(Schedule::class)->events())->map(function (Event $event) {
            $last = Cache::get($this->key($event));

            $job = collect(ScheduleSettings::JOBS)->search(fn (array $j) => str_contains($event->getSummaryForDisplay(), $j['command']) || $event->getSummaryForDisplay() === $j['name']) ?: null;

            return [
                'job' => $job, 'frequency' => $job ? app(ScheduleSettings::class)->get($job) + ['is_default' => app(ScheduleSettings::class)->isDefault($job), 'choices' => collect(ScheduleSettings::JOBS[$job]['choices'])->map(fn ($c) => ['value' => $c, 'label' => ScheduleSettings::LABELS[$c]])->all()] : null,
                'label' => $this->label($event), 'expression' => $event->expression,
                'last_run' => $last['at'] ?? null, 'seconds' => $last['seconds'] ?? null, 'ok' => $last['ok'] ?? null, 'error' => $last['error'] ?? null,
                'next_run' => $event->nextRunDate()->toIso8601String(),
            ];
        })->values()->all();
    }

    private function store(Event $event, bool $ok, ?float $seconds, ?string $error): void
    {
        Cache::forever($this->key($event), ['at' => now()->toIso8601String(), 'seconds' => $seconds, 'ok' => $ok, 'error' => $error]);
    }

    private function key(Event $event): string
    {
        return 'schedule.last.'.md5($event->expression.'|'.$event->getSummaryForDisplay());
    }

    private function label(Event $event): string
    {
        $summary = $event->getSummaryForDisplay();
        foreach (self::LABELS as $needle => $label) {
            if (str_contains($summary, $needle)) {
                return $label;
            }
        }

        return $summary;
    }
}
