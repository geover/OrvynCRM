<?php

namespace App\Jobs;

use App\Integrations\Twilio\TwilioClient;
use App\Models\Activity;
use App\Models\SmsMessage;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSmsMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(public int $messageId)
    {
        $this->onQueue('messages');
    }

    public function handle(TwilioClient $twilio, CompanyServices $services): void
    {
        // sending switched off for this company (Platform > Background services): whoever pressed Send, the message stays
        // queued and goes out on the first tick after it is switched on again
        if (! $services->enabled('messages')) {
            return;
        }

        if ($services->holdsMessage(SmsMessage::whereKey($this->messageId)->value('data_object_record_id'))) {
            return;                                  // this data object's message sending is switched off: it stays queued
        }

        if (! SmsMessage::whereKey($this->messageId)->where('status', 'queued')->update(['status' => 'sending'])) {
            return;
        }

        $sms = SmsMessage::findOrFail($this->messageId);

        try {
            $sid = $twilio->send($sms->to_number, $sms->body, $sms->from_number);
            $sms->update(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $sid, 'error' => null]);
            Activity::create([
                'contact_id' => $sms->contact_id, 'type' => 'sms.sent',
                'subject_type' => SmsMessage::class, 'subject_id' => $sms->id, 'data' => ['body' => $sms->body],
            ]);
        } catch (\Throwable $e) {
            $sms->update(['status' => $this->attempts() >= $this->tries ? 'failed' : 'queued', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
