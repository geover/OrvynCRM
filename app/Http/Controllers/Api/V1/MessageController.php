<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailMessage;
use App\Jobs\SendSmsMessage;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\Integration;
use App\Models\SmsMessage;
use App\Services\Messaging\MessageComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Replaces V1::email_post/get, sms_post, communication_*, messaging_queue_get, resend_email, email_suppression_delete. */
class MessageController extends Controller
{
    public function history(Request $request, Contact $contact): JsonResponse
    {
        $limit = min($request->integer('limit', 50), 200);

        return response()->json([
            'emails' => $contact->emails()->latest('id')->limit($limit)
                ->get(['id', 'subject', 'status', 'open_count', 'click_count', 'sent_at', 'created_at', 'scheduled_at', 'user_id', 'error', 'from_email']),
            'sms' => SmsMessage::where('contact_id', $contact->id)->latest('id')->limit($limit)->get(['id', 'body', 'status', 'direction', 'sent_at', 'created_at', 'error', 'to_number']),
            'suppressed' => $contact->email ? EmailSuppression::where('email', $contact->email)->exists() : false,
        ]);
    }

    public function showEmail(EmailMessage $email): JsonResponse
    {
        return response()->json(['data' => $email->only(['id', 'contact_id', 'subject', 'html', 'status', 'to', 'cc', 'bcc', 'from_email', 'from_name', 'open_count', 'click_count', 'sent_at', 'error'])]);
    }

    public function resend(Request $request, EmailMessage $email): JsonResponse
    {
        $copy = $email->replicate(['tracker_id', 'open_count', 'click_count', 'sent_at', 'error', 'provider_message_id']);
        $copy->forceFill(['status' => 'queued', 'scheduled_at' => null, 'user_id' => $request->user()->id])->save();
        SendEmailMessage::dispatch($copy->id);

        return response()->json(['data' => $copy->only(['id', 'status'])], 202);
    }

    public function email(Request $request, Contact $contact, MessageComposer $composer): JsonResponse
    {
        $user = $request->user();
        $spec = $request->validate([
            'subject' => ['required', 'string', 'max:255'], 'html' => ['required', 'string', 'max:500000'],
            'template_id' => ['nullable', 'exists:email_templates,id'], 'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'test' => ['boolean'],
            'cc' => ['nullable', 'array', 'max:10'], 'cc.*' => ['email'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.path' => ['required', 'string'], 'attachments.*.name' => ['required', 'string', 'max:255'],
        ]);

        // Only files THIS user uploaded through /uploads may be attached (blocks path traversal / other users' files).
        foreach ($spec['attachments'] ?? [] as $a) {
            abort_unless(Str::startsWith($a['path'], "tmp/{$user->id}/") && ! Str::contains($a['path'], '..') && Storage::disk('local')->exists($a['path']), 422, 'Invalid attachment.');
        }

        $sig = $user->signature;
        if ($sig && ! $request->boolean('no_signature')) {
            $spec['html'] .= '<br><br>'.$sig;
        }

        // "Send me a test": renders for this contact but delivers to the sender, immediately, without touching history.
        if ($request->boolean('test')) {
            $message = $composer->queueEmail($contact, $spec);
            abort_if(! $message, 422, 'Contact cannot receive email (missing, opted out, or suppressed).');
            $message->update(['to' => [['email' => $user->email, 'name' => $user->name]], 'subject' => '[TEST] '.$message->subject, 'user_id' => $user->id, 'attachments' => $spec['attachments'] ?? null]);
            SendEmailMessage::dispatchSync($message->id);

            return response()->json(['data' => ['id' => $message->id, 'status' => $message->fresh()->status, 'sent_to' => $user->email]], 202);
        }

        $message = $composer->queueEmail($contact, $spec);
        abort_if(! $message, 422, 'Contact cannot receive email (missing, opted out, or suppressed).');
        $message->update([
            'user_id' => $user->id, 'attachments' => $spec['attachments'] ?? null,
            'cc' => collect($spec['cc'] ?? [])->map(fn ($e) => ['email' => $e])->all() ?: null,
        ]);

        if (! $message->scheduled_at) {
            SendEmailMessage::dispatch($message->id);
        }

        return response()->json(['data' => $message->only(['id', 'status', 'scheduled_at'])], 202);
    }

    public function sms(Request $request, Contact $contact, MessageComposer $composer): JsonResponse
    {
        $spec = $request->validate(['body' => ['required', 'string', 'max:1600'], 'delay_minutes' => ['nullable', 'integer', 'min:0']]);
        abort_if(! Integration::for('twilio'), 422, 'SMS is not configured. Ask an administrator to enable the Twilio integration.');

        $message = $composer->queueSms($contact, $spec);
        abort_if(! $message, 422, 'Contact has no phone number.');
        $message->update(['user_id' => $request->user()->id]);

        if (! $message->scheduled_at) {
            SendSmsMessage::dispatch($message->id);
        }

        return response()->json(['data' => $message->only(['id', 'status', 'scheduled_at'])], 202);
    }

    public function queue(Request $request)
    {
        // Admins see everyone's queue; users only their own.
        $scope = fn ($q) => $request->user()->isAdmin() && ! $request->boolean('mine') ? $q : $q->where('user_id', $request->user()->id);

        return response()->json([
            'emails' => $scope(EmailMessage::query())->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'sms' => $scope(SmsMessage::query())->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** Admin "Blast Progress": queued/sending/failed messages per user, newest first. */
    public function blast(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin() || $request->integer('user_id') === $request->user()->id || ! $request->has('user_id'), 403);
        $userId = $request->user()->isAdmin() ? $request->integer('user_id') : $request->user()->id;

        $emails = EmailMessage::with('contact:id,first_name,last_name')->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->whereIn('status', ['queued', 'sending', 'failed'])->latest('id')->limit(100)->get(['id', 'contact_id', 'user_id', 'subject', 'status', 'scheduled_at', 'error', 'created_at']);
        $sms = SmsMessage::with('contact:id,first_name,last_name')->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->whereIn('status', ['queued', 'sending', 'failed'])->latest('id')->limit(100)->get(['id', 'contact_id', 'user_id', 'body', 'status', 'scheduled_at', 'error', 'created_at']);

        $count = fn ($m) => $m::query()->when($userId, fn ($q) => $q->where('user_id', $userId))->where('created_at', '>=', now()->subDays(7))->selectRaw('status, count(*) t')->groupBy('status')->pluck('t', 'status');

        return response()->json(['emails' => $emails, 'sms' => $sms, 'summary' => ['emails' => $count(EmailMessage::class), 'sms' => $count(SmsMessage::class)]]);
    }

    public function unsuppress(Request $request, string $email): JsonResponse
    {
        abort_unless($request->user()->isAdmin() || $request->user()->hasPermission('contacts.edit'), 403);
        EmailSuppression::where('email', strtolower($email))->delete();

        return response()->json(null, 204);
    }
}
