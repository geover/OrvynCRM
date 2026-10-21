<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SupportRequest;
use App\Services\Tenancy\MailSettings;
use App\Support\SafeHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The "Internal support" tab of Messages: the conversations people of the company started with "Contact support". Administrators only. */
class SupportRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $rows = SupportRequest::with('replies')->orderByDesc('last_activity_at')->orderByDesc('id')->limit(300)->get();
        $contacts = Contact::whereIn('id', $rows->pluck('contact_id')->filter())->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn (SupportRequest $r) => $this->row($r, $contacts->get($r->contact_id)))->values(), 'unread' => $rows->where('unread_admin', true)->count()]);
    }

    /** How many conversations have something the administrators have not read (the tab and the menu badge). */
    public function poll(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['unread' => SupportRequest::where('unread_admin', true)->count(), 'total' => SupportRequest::count(), 'sig' => SupportRequest::count().'|'.SupportRequest::max('updated_at')]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        SupportRequest::where('id', $id)->update(['unread_admin' => false]);

        return response()->json(['ok' => true]);
    }

    /** Answers the person: kept in the conversation (they read it in their Contact support window) and, when the company wants it, e-mailed to them. */
    public function reply(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $body = SupportRequest::html($d['body']);
        abort_if(SupportRequest::isBlank($body), 422, 'Write your answer.');
        $support = SupportRequest::findOrFail($id);
        $u = $request->user();

        $support->replies()->create(['user_id' => $u->id, 'name' => $u->name, 'body' => $body, 'side' => 'admin']);
        $support->forceFill(['unread_user' => true, 'unread_admin' => false, 'last_activity_at' => now()])->save();
        if ($support->user_id) {
            DB::table('notifications_feed')->insert([                    // the bell: opens Contact support on that conversation
                'user_id' => $support->user_id, 'title' => "Support answered: {$support->subject}", 'body' => str(SafeHtml::toText($body))->squish()->limit(120)->toString(),
                'url' => "/?support={$support->id}", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (SupportRequest::emailsPerson()) {
            $from = app(MailSettings::class)->from();
            $m = EmailMessage::create([
                'user_id' => $u->id, 'subject' => 'Re: [Support] '.$support->subject, 'from_email' => $from['address'], 'from_name' => $from['name'], 'to' => [['email' => $support->email]],
                'html' => $body.'<p style="color:#64748b;font-size:12px">'.e($u->name).' answered your message to support: <i>'.e($support->subject).'</i>. You can read it again, and reply, under Contact support in the account menu.</p>',
            ]);
            SendEmailMessage::dispatch($m->id);
        }

        return response()->json(['data' => $this->row($support->load('replies'), $support->contact_id ? Contact::find($support->contact_id) : null)]);
    }

    /** @return array<string, mixed> */
    private function row(SupportRequest $r, ?Contact $contact): array
    {
        return $r->conversation() + [
            'contact' => $contact ? ['id' => $contact->id, 'name' => $contact->full_name] : null,
            'sent_to' => $r->sent_to, 'unread' => $r->unread_admin,
        ];
    }
}
