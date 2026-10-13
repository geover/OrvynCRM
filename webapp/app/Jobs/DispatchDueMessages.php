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

        // at most one minute's allowance per company and channel, oldest first: a backlog cannot flood the queue other companies share
        $cap = max(1, (int) config('queue.messages_per_tick', 600));
        $held(EmailMessage::due())->orderBy('id')->limit($cap)->pluck('id')->each(fn ($id) => SendEmailMessage::dispatch($id));
        $held(SmsMessage::due())->orderBy('id')->limit($cap)->pluck('id')->each(fn ($id) => SendSmsMessage::dispatch($id));
    }
}
