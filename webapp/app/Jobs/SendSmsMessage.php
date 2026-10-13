<?php

namespace App\Jobs;

use App\Integrations\Twilio\TwilioClient;
use App\Models\Activity;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\UsageTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendSmsMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(public int $messageId)
    {
        $this->onQueue('messages');
    }

    public function handle(TwilioClient $twilio, CompanyServices $services, Plans $plans): void
    {
        // sending switched off for this company (Platform > Background services): whoever pressed Send, the message stays
        // queued and goes out on the first tick after it is switched on again
        if (! $services->enabled('messages')) {
            return;
        }

        if ($services->holdsMessage(SmsMessage::whereKey($this->messageId)->value('data_object_record_id'))) {
            return;                                  // this data object's message sending is switched off: it stays queued
        }

        // Claim the text and check the month's limit as one step per company, so two texts sent at the same moment cannot both squeeze under
        // the limit. The text being sent does not count against itself. Past the limit it is not sent (and not retried).
        $company = Tenant::find(tenant('id'));
        $overLimit = false;
        $claimed = Cache::lock('sms-cap:'.tenant('id'), 10)->block(5, function () use ($plans, $company, &$overLimit) {
            if (! SmsMessage::whereKey($this->messageId)->where('status', 'queued')->update(['status' => 'sending'])) {
                return false;
            }
            if ($company && $plans->smsExhausted($company, $this->messageId)) {
                SmsMessage::whereKey($this->messageId)->update(['status' => 'failed', 'error' => "This month's limit of {$plans->smsLimit($company)} texts for your plan is used up. It resets at the start of next month, or choose a bigger plan."]);
                $overLimit = true;

                return false;
            }

            return true;
        });
        if (! $claimed) {
            $overLimit && $company && app(UsageTracker::class)->refresh($company);       // the company is told it has used up its texts

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
