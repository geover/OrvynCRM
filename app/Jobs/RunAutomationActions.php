<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Services\Automation\ActionRunner;
use App\Services\Automation\FailureNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunAutomationActions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $runId)
    {
        $this->onQueue('automations');
    }

    public function handle(ActionRunner $runner): void
    {
        $run = AutomationRun::with('automation')->find($this->runId);
        if (! $run || $run->status === 'done') {
            return;
        }

        $results = $runner->run($run);
        $run->update(['status' => 'done', 'result' => $results]);

        if (collect($results)->contains(fn ($r) => ($r['status'] ?? null) === 'failed')) {
            app(FailureNotifier::class)->notify($run, $results);
        }
    }

    public function failed(Throwable $e): void
    {
        AutomationRun::whereKey($this->runId)->update(['status' => 'failed', 'result' => json_encode(['error' => $e->getMessage()])]);

        if ($run = AutomationRun::find($this->runId)) {
            app(FailureNotifier::class)->notify($run, $e->getMessage());
        }
    }
}
