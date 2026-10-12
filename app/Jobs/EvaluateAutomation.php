<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Services\DataObjects\RecordQuery;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

/**
 * Finds records matching an automation's conditions that have not been triggered yet and fans out
 * one RunAutomationActions job per match. Replaces the endless polling loop in services/_service.php:
 * the "already triggered" check is a unique key (automation_runs), not a full rescan.
 */
class EvaluateAutomation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $automationId, public ?int $recordId = null)
    {
        $this->onQueue('automations');
    }

    /**
     * One evaluation of an automation at a time (per company: the lock lives in the company's cache namespace).
     * Without this, an "every minute" automation that takes longer than a minute is evaluated again while the first
     * pass is still running, and the backlog grows. A second pass is dropped, not queued: the next tick covers it.
     * Evaluations of a single record (event-driven) take the same lock only for that record.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("automation-{$this->automationId}-".($this->recordId ?? 'all')))->expireAfter($this->timeout + 60)->dontRelease()];
    }

    public function handle(CompanyServices $services): void
    {
        if (! $services->enabled('automations')) {
            return;                                  // switched off by the platform: nothing new starts (runs already started finish)
        }

        $automation = Automation::active()->with('dataObject.fields')->find($this->automationId);
        if (! $automation?->dataObject) {
            return;
        }
        if (! $services->objectEnabled('automations', $automation->data_object_id)) {
            return;                                  // this data object's automations are switched off by the platform
        }

        $query = (new RecordQuery($automation->dataObject))->build($automation->conditions ?? []);
        if ($this->recordId) {
            $query->whereKey($this->recordId);
        }

        // Anti-join on the unique run table; never loads records that already fired.
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('automation_runs as ar')
            ->whereColumn('ar.data_object_record_id', 'data_object_records.id')
            ->where('ar.automation_id', $automation->id));

        $query->select('data_object_records.id')->chunkById(500, function ($records) use ($automation) {
            foreach ($records as $record) {
                $run = AutomationRun::firstOrCreate(
                    ['automation_id' => $automation->id, 'data_object_record_id' => $record->id, 'contact_id' => null],
                    ['status' => 'pending'],
                );
                if ($run->wasRecentlyCreated) {
                    RunAutomationActions::dispatch($run->id);
                }
            }
        }, 'data_object_records.id', 'id');

        $automation->forceFill(['last_run_at' => now()])->saveQuietly();
    }
}
