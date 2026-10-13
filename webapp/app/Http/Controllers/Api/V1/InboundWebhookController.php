<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\Tenancy\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Provider callbacks (SendGrid events, Twilio status). Signature verification is per provider. */
class InboundWebhookController extends Controller
{
    public function sendgrid(Request $request): JsonResponse
    {
        $this->authorizeProvider($request, 'sendgrid');
        $this->applySendgridEvents((array) $request->json()->all());

        return response()->json(['ok' => true]);
    }

    /**
     * The platform's SendGrid account is one for every company, so it can report to one address only. Each e-mail carries its
     * company in a custom argument (see SendEmailMessage); the events are sorted by it and applied inside that company.
     */
    public function sendgridPlatform(Request $request, MailSettings $mail): JsonResponse
    {
        abort_unless(hash_equals($mail->sendgridToken(), (string) $request->query('token')), 403);
        abort_unless($mail->validSendgridSignature($request->getContent(), $request->header('X-Twilio-Email-Event-Webhook-Signature'), $request->header('X-Twilio-Email-Event-Webhook-Timestamp')), 403, 'The signature of the event report is not valid.');

        collect((array) $request->json()->all())->filter(fn ($e) => is_array($e) && ! empty($e['tenant_id']))->groupBy('tenant_id')
            ->each(fn ($events, $id) => Tenant::find($id)?->run(fn () => $this->applySendgridEvents($events->all())));

        return response()->json(['ok' => true]);
    }

    /** @param  array<int, mixed>  $events */
    private function applySendgridEvents(array $events): void
    {
        foreach ($events as $event) {
            $email = $event['email'] ?? null;
            match ($event['event'] ?? null) {
                'bounce', 'dropped', 'spamreport', 'unsubscribe' => $email && EmailSuppression::firstOrCreate(['email' => $email], ['reason' => $event['event']]),
                'click' => isset($event['tracker_id']) && EmailMessage::where('tracker_id', $event['tracker_id'])->increment('click_count'),
                default => null,
            };
        }
    }

    public function twilio(Request $request): JsonResponse
    {
        $this->authorizeProvider($request, 'twilio');

        if ($sid = $request->input('MessageSid')) {
            SmsMessage::where('provider_message_id', $sid)->update(['status' => match ($request->input('MessageStatus')) {
                'delivered' => 'delivered', 'undelivered', 'failed' => 'failed', default => 'sent',
            }]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * With companies, the token in the URL is per company and has already been matched to one (tenant.public:webhook
     * answers 404 for an unknown token). In single-database mode (tests) the old per-provider config token applies.
     */
    private function authorizeProvider(Request $request, string $provider): void
    {
        if (tenancy()->initialized) {
            return;
        }
        abort_unless(hash_equals((string) config("services.{$provider}.webhook_token"), (string) $request->query('token')), 403);
    }
}
