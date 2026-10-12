<?php

namespace App\Services\Tenancy;

use App\Models\MessageThread;
use App\Models\ThreadMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the conversation pages ask every few seconds, so new messages appear without a refresh: a fingerprint of the
 * threads a viewer can see (one cheap query), and, only when it changed, the new messages of the thread they have open.
 */
class ThreadPoll
{
    /**
     * A short fingerprint that changes whenever anything visible changes: a thread appears, a message arrives, a
     * thread is read, archived or deleted from one side.
     *
     * @param  Builder<MessageThread>  $threads  the threads the viewer can see
     */
    public function signature(Builder $threads): string
    {
        $flags = (clone $threads)->toBase()->selectRaw(
            'count(*) as n, max(updated_at) as u, '
            .'sum(case when unread_platform then 1 else 0 end) as up, sum(case when unread_tenant then 1 else 0 end) as ut, '
            .'sum(case when archived_at is not null then 1 else 0 end) as a, sum(case when tenant_archived_at is not null then 1 else 0 end) as ta, '
            .'sum(case when tenant_deleted_at is not null then 1 else 0 end) as td'
        )->first();
        $latestMessage = ThreadMessage::whereIn('thread_id', (clone $threads)->select('id'))->max('id');

        return md5(json_encode([(array) $flags, $latestMessage]));
    }

    /**
     * Messages of an open thread newer than the one the viewer already has.
     *
     * @return list<array<string, mixed>>
     */
    public function newMessages(MessageThread $thread, int $after, string $side): array
    {
        return $thread->messages()->with('attachments')->where('id', '>', $after)->get()->map->toArrayForViewer($side)->values()->all();
    }
}
