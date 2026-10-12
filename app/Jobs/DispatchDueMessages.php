<?php

namespace App\Jobs;

use App\Models\DataObjectRecord;
use App\Models\EmailMessage;
use App\Models\SmsMessage;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduler tick (every minute) that replaces the process_email / process_sms service loops.
 * Only fans out ids; actual delivery runs in parallel, rate-limited queue workers.
 */
class DispatchDueMessages implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('messages');
    }

    public function handle(CompanyServices $services): void
    {
        if (! $services->enabled('messages')) {
            return;                                  // switched off after this job was queued: due messages wait
        }

        // a data object whose message sending is switched off keeps its messages queued until it is switched on again
        $held = fn ($q) => (tenant('id') && ($paused = $services->pausedObjects(tenant('id'), 'messages'))) ? $q->where(fn ($w) => $w->whereNull('data_object_record_id')
            ->orWhereNotIn('data_object_record_id', DataObjectRecord::whereIn('data_object_id', $paused)->select('id'))) : $q;

        $held(EmailMessage::due())->select('id')->chunkById(500, fn ($rows) => $rows->each(fn ($m) => SendEmailMessage::dispatch($m->id)));
        $held(SmsMessage::due())->select('id')->chunkById(500, fn ($rows) => $rows->each(fn ($m) => SendSmsMessage::dispatch($m->id)));
    }
}
