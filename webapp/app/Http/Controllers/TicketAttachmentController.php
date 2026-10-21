<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A company administrator opening a file from one of the company's own tickets (never one from a platform note). */
class TicketAttachmentController extends Controller
{
    public function show(Request $request, TicketAttachment $attachment): Response
    {
        abort_unless(tenancy()->initialized && $request->user()->isAdmin() && ! $request->user()->is_platform_support, 403);
        $reply = $attachment->reply;
        $ticket = $reply ? Ticket::find($reply->ticket_id) : null;
        abort_unless($ticket && $ticket->tenant_id === tenant('id') && $reply->kind !== 'note', 404);

        return $attachment->response();
    }
}
