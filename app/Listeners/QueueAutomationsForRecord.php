<?php

namespace App\Listeners;

use App\Events\DataObjectRecordChanged;
use App\Jobs\EvaluateAutomation;
use App\Models\Automation;

/** Event-driven half of the automation engine (the scheduled half lives in `automations:run`). */
class QueueAutomationsForRecord
{
    public function handle(DataObjectRecordChanged $event): void
    {
        Automation::active()
            ->where('schedule', 'on_change')
            ->where('data_object_id', $event->record->data_object_id)
            ->pluck('id')
            ->each(fn ($id) => EvaluateAutomation::dispatch($id, $event->record->id));
    }
}
