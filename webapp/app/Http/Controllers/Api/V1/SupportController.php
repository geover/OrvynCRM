<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailMessage;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Models\SupportRequest;
use App\Services\Tenancy\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Contact support", for every user of the company: a conversation with the company's own support team. What is written is kept
 * (the person sees it again in the same window, the administrators read it under Messages > Internal support) and, when the company
 * wants it, also e-mailed to the internal support addresses.
 */
class SupportController extends Controller
{
    /** How many conversations of the person have an answer they have not read (the dot and the badge). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => SupportRequest::where('user_id', $request->user()->id)->where('unread_user', true)->count()]);
    }

    /** A new conversation. */
    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->validate(['subject' => ['required', 'string', 'max:200'], 'message' => ['required', 'string', 'max:20000'], 'contact_id' => ['nullable', 'exists:contacts,id']]);
        $to = (array) Setting::get('support.internal_emails', []);
        abort_if(SupportRequest::emailsAdmins() && ! $to, 422, 'No internal support emails are configured. Ask an administrator to add one under Company settings.');
        $message = SupportRequest::html($d['message']);
        abort_if(SupportRequest::isBlank($message), 422, 'Write how we can help.');
        $u = $request->user();

        $support = SupportRequest::create([
            'user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'subject' => $d['subject'], 'message' => $message, 'contact_id' => $d['contact_id'] ?? null,
            'sent_to' => SupportRequest::emailsAdmins() ? array_values($to) : [], 'unread_admin' => true, 'last_activity_at' => now(),
        ]);
        $body = '<p><b>From:</b> '.e($u->name).' &lt;'.e($u->email).'&gt;</p>'
            .(isset($d['contact_id']) ? '<p><b>Contact:</b> <a href="'.e(url("/contacts/{$d['contact_id']}")).'">'.e(url("/contacts/{$d['contact_id']}")).'</a></p>' : '')
            .$message;
        $sent = $this->mailSupport($u->id, '[Support] '.$d['subject'], $body, $u->email, $to);

        return response()->json(['sent' => $sent, 'data' => $support->load('replies')->conversation()], 202);
    }

    /** The person's own conversations, newest activity first. */
    public function conversations(Request $request): JsonResponse
    {
        $rows = SupportRequest::with('replies')->where('user_id', $request->user()->id)->orderByDesc('last_activity_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (SupportRequest $r) => $r->conversation() + ['unread' => $r->unread_user])->values()]);
    }

    /** The person writes again in a conversation of theirs. */
    public function write(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $body = SupportRequest::html($d['body']);
        abort_if(SupportRequest::isBlank($body), 422, 'Write your message.');
        $u = $request->user();
        $support = SupportRequest::where('user_id', $u->id)->findOrFail($id);

        $support->replies()->create(['user_id' => $u->id, 'name' => $u->name, 'body' => $body, 'side' => 'user']);
        $support->forceFill(['unread_admin' => true, 'unread_user' => false, 'last_activity_at' => now()])->save();
        $this->mailSupport($u->id, 'Re: [Support] '.$support->subject, '<p><b>From:</b> '.e($u->name).' &lt;'.e($u->email).'&gt;</p>'.$body, $u->email, (array) Setting::get('support.internal_emails', []));

        return response()->json(['data' => $support->load('replies')->conversation() + ['unread' => false]]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        SupportRequest::where('user_id', $request->user()->id)->where('id', $id)->update(['unread_user' => false]);

        return response()->json(['ok' => true]);
    }

    /** @param  list<string>  $to */
    private function mailSupport(int $userId, string $subject, string $html, string $copy, array $to): int
    {
        if (! SupportRequest::emailsAdmins()) {
            return 0;
        }
        $from = app(MailSettings::class)->from();
        foreach ($to as $address) {
            $m = EmailMessage::create([
                'user_id' => $userId, 'subject' => $subject, 'html' => $html, 'from_email' => $from['address'], 'from_name' => $from['name'], 'to' => [['email' => $address]], 'cc' => [['email' => $copy]],
            ]);
            SendEmailMessage::dispatch($m->id);
        }

        return count($to);
    }
}
