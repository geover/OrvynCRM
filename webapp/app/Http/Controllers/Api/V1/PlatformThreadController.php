<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessageThread;
use App\Models\SupportRequest;
use App\Models\ThreadMessage;
use App\Services\Tenancy\ThreadAttachments;
use App\Services\Tenancy\ThreadMailer;
use App\Services\Tenancy\ThreadPoll;
use App\Support\SafeHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A company's conversations with the platform. Administrators of the company only (any of them can read and write in
 * the company's threads); a new message starts a new thread, replies stay in it. A message can carry images.
 * A company can archive a conversation and delete it from its own view; the platform keeps its record.
 */
class PlatformThreadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $threads = $this->rows();

        return response()->json(['data' => $threads, 'unread' => $threads->where('unread', true)->count()]);
    }

    /**
     * Asked every few seconds by the open page: has anything changed? (A fingerprint match answers in one query.) When it
     * has, the thread list again and the new messages of the open thread; reading them here marks the thread read.
     * `counts=1` answers only the unread number (the menu badge).
     */
    public function poll(Request $request, ThreadPoll $poll): JsonResponse
    {
        $this->authorizeAdmin($request);
        $visible = fn () => MessageThread::visibleToTenant((string) tenant('id'));
        $unread = fn () => $visible()->where('unread_tenant', true)->count();

        if ($request->boolean('counts')) {
            return response()->json(['unread' => $unread() + SupportRequest::where('unread_admin', true)->count()]);                // the menu badge: the platform and internal support
        }
        $signature = $poll->signature($visible());
        if ($signature === $request->query('sig')) {
            return response()->json(['changed' => false, 'unread' => $unread()]);
        }

        $open = $request->integer('thread') ? $visible()->find($request->integer('thread')) : null;
        $messages = $open ? $poll->newMessages($open, $request->integer('after'), 'tenant') : [];
        if ($open && $messages && $open->unread_tenant) {
            $open->forceFill(['unread_tenant' => false])->save();                   // they are looking at it: read
            $signature = $poll->signature($visible());
        }

        return response()->json([
            'changed' => true, 'sig' => $signature, 'unread' => $unread(), 'threads' => $this->rows(),
            'open' => $open ? ['id' => $open->id, 'archived' => $open->tenant_archived_at !== null, 'messages' => $messages] : null,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> the company's visible threads, newest first */
    private function rows(): Collection
    {
        $threads = MessageThread::visibleToTenant((string) tenant('id'))->withCount('messages')->orderByDesc('last_message_at')->limit(200)->get();
        $last = ThreadMessage::withCount('attachments')->whereIn('id', ThreadMessage::whereIn('thread_id', $threads->pluck('id'))->selectRaw('max(id)')->groupBy('thread_id'))->get()->keyBy('thread_id');

        return $threads->map(fn (MessageThread $t) => [
            'id' => $t->id, 'subject' => $t->subject, 'messages' => $t->messages_count, 'unread' => $t->unread_tenant,
            'archived' => $t->tenant_archived_at !== null, 'last_message_at' => $t->last_message_at?->toIso8601String(),
            'last' => isset($last[$t->id]) ? ['from' => $last[$t->id]->sender_type, 'name' => $last[$t->id]->sender_name, 'snippet' => $this->snippet($last[$t->id])] : null,
        ])->values();
    }

    /** Open a thread (this marks it read for the company). */
    public function show(Request $request, MessageThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $thread->unread_tenant && $thread->forceFill(['unread_tenant' => false])->save();

        return response()->json(['data' => $this->payload($thread)]);
    }

    /** A new message: a new thread. */
    public function store(Request $request, ThreadAttachments $attachments, ThreadMailer $mailer): JsonResponse
    {
        $this->authorizeAdmin($request);
        $d = $request->validate(['subject' => ['required', 'string', 'max:160']] + $attachments->rules());

        $thread = MessageThread::start(tenant('id'), $d['subject'], $this->sender($request), $d['body'] ?? null, $attachments->files($request));
        $mailer->toPlatform($thread, $thread->messages()->first(), true);

        return response()->json(['data' => $this->payload($thread)], 201);
    }

    /** A reply: it stays in the same thread. */
    public function reply(Request $request, MessageThread $thread, ThreadAttachments $attachments, ThreadMailer $mailer): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $d = $request->validate($attachments->rules());

        $message = $thread->post('tenant', $this->sender($request), $d['body'] ?? null, $attachments->files($request));
        $mailer->toPlatform($thread, $message, false);

        return response()->json(['data' => $this->payload($thread->refresh())], 201);
    }

    /** Tuck a conversation away (the company's own view only); a new reply from the platform brings it back. */
    public function archive(Request $request, MessageThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $thread->forceFill(['tenant_archived_at' => now(), 'unread_tenant' => false])->save();

        return response()->json(['archived' => true]);
    }

    public function unarchive(Request $request, MessageThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $thread->forceFill(['tenant_archived_at' => null])->save();

        return response()->json(['archived' => false]);
    }

    /**
     * Delete a conversation from the company's view for good. Only archived ones, so nothing active vanishes by
     * accident. The platform keeps its own copy: this never removes what the platform's administrators see.
     */
    public function destroy(Request $request, MessageThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        abort_unless($thread->tenant_archived_at, 422, 'Archive the conversation first; only archived conversations can be deleted.');
        $thread->forceFill(['tenant_deleted_at' => now(), 'unread_tenant' => false])->save();

        return response()->json(null, 204);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(tenancy()->initialized && $request->user()->isAdmin() && ! $request->user()->is_platform_support, 403);
    }

    private function authorizeThread(Request $request, MessageThread $thread): void
    {
        $this->authorizeAdmin($request);
        abort_unless($thread->tenant_id === tenant('id') && $thread->tenant_deleted_at === null, 404);          // only this company's threads exist for it
    }

    /** @return array{account_id: int|null, name: string, email: string} */
    private function sender(Request $request): array
    {
        return ['account_id' => $request->user()->central_user_id, 'name' => $request->user()->name, 'email' => $request->user()->email];
    }

    private function snippet(ThreadMessage $m): string
    {
        $text = str(SafeHtml::toText($m->body))->squish()->limit(110)->toString();

        return $text !== '' ? $text : ($m->attachments_count ? '[image]' : '');
    }

    /** @return array<string, mixed> */
    private function payload(MessageThread $thread): array
    {
        return [
            'id' => $thread->id, 'subject' => $thread->subject, 'started_by' => $thread->started_by_name, 'archived' => $thread->tenant_archived_at !== null,
            'messages' => $thread->messages()->with('attachments')->get()->map->toArrayForViewer('tenant')->values(),
        ];
    }
}
