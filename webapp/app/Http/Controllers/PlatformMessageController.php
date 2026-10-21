<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\ThreadAttachment;
use App\Models\ThreadMessage;
use App\Services\Tenancy\ThreadAttachments;
use App\Services\Tenancy\ThreadMailer;
use App\Services\Tenancy\ThreadPoll;
use App\Support\SafeHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The platform's inbox: conversations with company administrators, in threads. Replies (with images) stay in the thread. */
class PlatformMessageController extends Controller
{
    public function index(Request $request): Response
    {
        $companies = Tenant::pluck('name', 'id');
        $selected = $request->integer('thread') ? MessageThread::find($request->integer('thread')) : null;
        if ($selected?->unread_platform) {
            $selected->forceFill(['unread_platform' => false])->save();                   // opening a thread reads it
        }

        return Inertia::render('Platform/Messages', [
            'threads' => $this->rows($companies),
            'sig' => app(ThreadPoll::class)->signature(MessageThread::query()),
            'selected' => $selected ? [
                'id' => $selected->id, 'company_id' => $selected->tenant_id, 'company' => $companies[$selected->tenant_id] ?? 'Deleted company',
                'subject' => $selected->subject, 'ticket_id' => $selected->ticket_id, 'archived_at' => $selected->archived_at?->toIso8601String(), 'company_deleted' => $selected->tenant_deleted_at !== null,
                'messages' => $selected->messages()->with('attachments')->get()->map->toArrayForViewer('platform')->values(),
            ] : null,
            'me' => $request->attributes->get('platform_identity')?->name,
        ]);
    }

    /**
     * Asked every few seconds by the open page: has anything changed? (A fingerprint match answers in one query.) When it
     * has, the thread list again and the new messages of the open thread; reading them here marks the thread read.
     * `counts=1` answers only the unread number (the menu badge).
     */
    public function poll(Request $request, ThreadPoll $poll): JsonResponse
    {
        $unread = fn () => MessageThread::unreadForPlatform()->count();
        if ($request->boolean('counts')) {
            return response()->json(['unread' => $unread()]);
        }
        $signature = $poll->signature(MessageThread::query());
        if ($signature === $request->query('sig')) {
            return response()->json(['changed' => false, 'unread' => $unread()]);
        }

        $open = $request->integer('thread') ? MessageThread::find($request->integer('thread')) : null;
        $messages = $open ? $poll->newMessages($open, $request->integer('after'), 'platform') : [];
        if ($open && $messages && $open->unread_platform) {
            $open->forceFill(['unread_platform' => false])->save();                   // they are looking at it: read
            $signature = $poll->signature(MessageThread::query());
        }

        return response()->json([
            'changed' => true, 'sig' => $signature, 'unread' => $unread(), 'threads' => $this->rows(Tenant::pluck('name', 'id')),
            'open' => $open ? ['id' => $open->id, 'archived_at' => $open->archived_at?->toIso8601String(), 'company_deleted' => $open->tenant_deleted_at !== null, 'messages' => $messages] : null,
        ]);
    }

    /**
     * @param  Collection<string, string>  $companies  names by company id
     * @return Collection<int, array<string, mixed>>
     */
    private function rows($companies): Collection
    {
        $threads = MessageThread::withCount('messages')->orderByDesc('last_message_at')->limit(300)->get();
        $last = ThreadMessage::withCount('attachments')->whereIn('id', ThreadMessage::whereIn('thread_id', $threads->pluck('id'))->selectRaw('max(id)')->groupBy('thread_id'))->get()->keyBy('thread_id');

        return $threads->map(fn (MessageThread $t) => [
            'id' => $t->id, 'company_id' => $t->tenant_id, 'company' => $companies[$t->tenant_id] ?? 'Deleted company', 'subject' => $t->subject,
            'messages' => $t->messages_count, 'unread' => $t->unread_platform, 'archived_at' => $t->archived_at?->toIso8601String(),
            'company_deleted' => $t->tenant_deleted_at !== null, 'last_message_at' => $t->last_message_at?->toIso8601String(),
            'last' => isset($last[$t->id]) ? ['from' => $last[$t->id]->sender_type, 'name' => $last[$t->id]->sender_name, 'snippet' => $this->snippet($last[$t->id])] : null,
        ])->values();
    }

    /** A platform administrator replies (text and/or images); it stays in the thread and the company's administrators are told. */
    public function reply(Request $request, MessageThread $thread, ThreadAttachments $attachments, ThreadMailer $mailer): RedirectResponse
    {
        abort_if($thread->archived_at, 422, 'Restore the conversation before replying.');
        abort_if($thread->tenant_deleted_at, 422, 'The company deleted this conversation, so a reply would not reach anyone.');
        $d = $request->validate($attachments->rules());
        /** @var AccountUser $me */
        $me = $request->attributes->get('platform_identity');

        $message = $thread->post('platform', ['account_id' => $me->id, 'name' => $me->name, 'email' => $me->email], $d['body'] ?? null, $attachments->files($request));
        $mailer->toCompany($thread, $message);

        return back()->with('success', 'Reply sent.');
    }

    /** An image of a conversation (platform side). */
    public function attachment(ThreadAttachment $attachment, ThreadAttachments $attachments): HttpResponse
    {
        return $attachments->response($attachment);
    }

    /** Mark as read or unread (a thread the company wrote last can be left unread to come back to). */
    public function read(Request $request, MessageThread $thread): RedirectResponse
    {
        $thread->update(['unread_platform' => ! $request->boolean('read', true)]);

        return back();
    }

    public function archive(MessageThread $thread): RedirectResponse
    {
        $thread->update(['archived_at' => now(), 'unread_platform' => false]);                // archived means dealt with: it stops counting as unread

        return back()->with('success', 'Archived.');
    }

    public function unarchive(MessageThread $thread): RedirectResponse
    {
        $thread->update(['archived_at' => null]);

        return back()->with('success', 'Restored.');
    }

    /** Permanent, with all its messages and images, for everybody (the company loses it too); only archived threads. */
    public function destroy(MessageThread $thread): RedirectResponse
    {
        abort_unless($thread->archived_at, 422, 'Archive it first; only archived conversations can be deleted permanently.');
        $thread->delete();

        return back()->with('success', 'Deleted.');
    }

    private function snippet(ThreadMessage $m): string
    {
        $text = str(SafeHtml::toText($m->body))->squish()->limit(110)->toString();

        return $text !== '' ? $text : ($m->attachments_count ? '[image]' : '');
    }
}
