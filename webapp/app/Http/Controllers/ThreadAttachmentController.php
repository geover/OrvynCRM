<?php

namespace App\Http\Controllers;

use App\Models\MessageThread;
use App\Models\ThreadAttachment;
use App\Services\Tenancy\ThreadAttachments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A company administrator opening an image from one of the company's own conversations with the platform. */
class ThreadAttachmentController extends Controller
{
    public function show(Request $request, ThreadAttachment $attachment, ThreadAttachments $attachments): Response
    {
        abort_unless(tenancy()->initialized && $request->user()->isAdmin() && ! $request->user()->is_platform_support, 403);
        $thread = MessageThread::whereKey($attachment->message()->value('thread_id'))->first();
        abort_unless($thread && $thread->tenant_id === tenant('id') && $thread->tenant_deleted_at === null, 404);

        return $attachments->response($attachment);
    }
}
