<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\EmailMessage;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class SendEmailMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [60, 300, 1800];

    public function __construct(public int $messageId)
    {
        $this->onQueue('messages');
    }

    public function middleware(): array
    {
        return [new RateLimited('outbound-email')];
    }

    public function handle(CompanyServices $services): void
    {
        // sending switched off for this company (Platform > Background services): whoever pressed Send, the message stays
        // queued and goes out on the first tick after it is switched on again
        if (! $services->enabled('messages')) {
            return;
        }

        if ($services->holdsMessage(EmailMessage::whereKey($this->messageId)->value('data_object_record_id'))) {
            return;                                  // this data object's message sending is switched off: it stays queued
        }

        // Atomic claim: only one worker may move a message queued -> sending.
        $claimed = EmailMessage::whereKey($this->messageId)->where('status', 'queued')->update(['status' => 'sending']);
        if (! $claimed) {
            return;
        }

        $message = EmailMessage::findOrFail($this->messageId);
        $pixel = '<img src="'.url('/t/o/'.$message->tracker_id).'" width="1" height="1" alt="" style="display:none">';

        try {
            Mail::html($message->html.$pixel, function ($mail) use ($message) {
                $mail->from($message->from_email, $message->from_name)->subject($message->subject);
                // SendGrid reports back with these: which message, and which company it belongs to (see InboundWebhookController)
                if (str_contains((string) config('mail.mailers.smtp.host'), 'sendgrid')) {
                    $mail->getSymfonyMessage()->getHeaders()->addTextHeader('X-SMTPAPI', json_encode(['unique_args' => ['tracker_id' => $message->tracker_id, 'tenant_id' => (string) tenant('id')]]));
                }
                foreach ($message->to as $to) {
                    $mail->to($to['email'], $to['name'] ?? null);
                }
                foreach ((array) $message->cc as $cc) {
                    $mail->cc($cc['email']);
                }
                foreach ((array) $message->bcc as $bcc) {
                    $mail->bcc($bcc['email']);
                }
                foreach ((array) $message->attachments as $a) {
                    if (isset($a['path']) && Storage::disk('local')->exists($a['path'])) {
                        $mail->attach(Storage::disk('local')->path($a['path']), ['as' => $a['name'] ?? basename($a['path'])]);
                    }
                }
            });

            $message->update(['status' => 'sent', 'sent_at' => now(), 'provider' => config('mail.default'), 'error' => null]);
            Activity::create([
                'contact_id' => $message->contact_id, 'type' => 'email.sent',
                'subject_type' => EmailMessage::class, 'subject_id' => $message->id,
                'data' => ['subject' => $message->subject],
            ]);
        } catch (\Throwable $e) {
            $message->update(['status' => $this->attempts() >= $this->tries ? 'failed' : 'queued', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
