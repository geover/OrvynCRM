<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\Support\Tickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Platform > Tickets: every company's tickets, worked on by the platform's administrators (replies, private notes, status, priority, owner). */
class PlatformTicketController extends Controller
{
    public function __construct(private readonly Tickets $tickets) {}

    public function index(Request $request): Response
    {
        $companies = Tenant::pluck('name', 'id');
        $admins = AccountUser::where('is_platform_admin', true)->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $names = $admins->pluck('name', 'id');
        $rows = Ticket::orderByDesc('last_activity_at')->limit(500)->get();
        $count = fn (string $s) => $rows->where('status', $s)->count();

        return Inertia::render('Platform/Tickets', [
            'tickets' => $rows->map(fn (Ticket $t) => $this->row($t, $companies, $names))->values(),
            'summary' => [
                'open' => $count('open'), 'waiting' => $count('waiting'), 'unassigned' => $rows->where('status', 'open')->whereNull('assignee_id')->count(),
                'resolved_7d' => $rows->whereIn('status', ['resolved', 'closed'])->filter(fn ($t) => $t->resolved_at?->gte(now()->subDays(7)) || $t->closed_at?->gte(now()->subDays(7)))->count(),
                'oldest_open_days' => (int) ($rows->where('status', 'open')->min('created_at')?->diffInDays(now()) ?? 0),
            ],
            'admins' => $admins->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'me' => $request->attributes->get('platform_identity')?->id,
            'settings' => ['auto_close_days' => $this->tickets->autoCloseDays(), 'email_company' => $this->tickets->emailsCompany()],
            'categories' => Ticket::CATEGORIES, 'priorities' => Ticket::PRIORITIES, 'focus' => $request->query('ticket'),
        ]);
    }

    /** One ticket in full: every line (private notes too), the context of the report and where it came from. Opening it reads it. */
    /** The menu badge and an open page ask this every few seconds: tickets that need us, and a signature that changes when any ticket does. */
    public function poll(): JsonResponse
    {
        return response()->json(['needing' => Ticket::needingPlatform()->count(), 'sig' => Ticket::query()->count().'|'.Ticket::query()->max('updated_at')]);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->unread_platform && $ticket->forceFill(['unread_platform' => false])->save();
        $companies = Tenant::pluck('name', 'id');

        return response()->json(['data' => array_merge($this->row($ticket, $companies, AccountUser::where('is_platform_admin', true)->pluck('name', 'id')), [
            'context' => $ticket->context, 'opened_by' => ['name' => $ticket->opened_by_name, 'email' => $ticket->opened_by_email], 'thread_id' => $ticket->thread_id,
            'first_response_at' => $ticket->first_response_at?->toIso8601String(), 'reopened_count' => $ticket->reopened_count,
            'replies' => $ticket->replies()->with('attachments')->get()->map->forViewer('platform')->values(),
        ])]);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $d = $request->validate([
            'body' => ['nullable', 'string', 'max:20000', 'required_without:attachments'], 'note' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.TicketAttachment::MAX_PER_REPLY], 'attachments.*' => ['file', 'mimes:'.implode(',', array_keys(TicketAttachment::TYPES)), 'max:'.TicketAttachment::MAX_KILOBYTES],
        ]);
        $files = array_values(array_filter((array) $request->file('attachments'), fn ($f) => $f instanceof UploadedFile));
        if ($files === [] && trim(html_entity_decode(strip_tags((string) ($d['body'] ?? '')), ENT_QUOTES | ENT_HTML5)) === '') {
            throw ValidationException::withMessages(['body' => 'Write something, or attach a file.']);
        }

        $this->tickets->reply($ticket, 'platform', $this->actor($request), $d['body'] ?? null, $files, $request->boolean('note'));

        return $this->show($ticket->refresh());
    }

    /** Status, priority, category and owner: any of them. */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $d = $request->validate([
            'status' => ['sometimes', Rule::in(Ticket::STATUSES)], 'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)], 'category' => ['sometimes', Rule::in(array_keys(Ticket::CATEGORIES))],
            'assignee_id' => ['sometimes', 'nullable', Rule::exists('account_users', 'id')->where('is_platform_admin', true)],
        ]);
        $actor = $this->actor($request);
        isset($d['status']) && $this->tickets->setStatus($ticket, $d['status'], 'platform', $actor);
        $this->tickets->classify($ticket->refresh(), $d, $actor);
        array_key_exists('assignee_id', $d) && (int) $d['assignee_id'] !== (int) $ticket->refresh()->assignee_id && $this->tickets->assign($ticket, $d['assignee_id'] ? (int) $d['assignee_id'] : null, $actor);

        return $this->show($ticket->refresh());
    }

    /** A Messages conversation that is really an issue becomes a ticket. */
    public function fromThread(Request $request, MessageThread $thread): JsonResponse
    {
        $d = $request->validate(['category' => ['sometimes', Rule::in(array_keys(Ticket::CATEGORIES))], 'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)]]);
        $ticket = $this->tickets->fromThread($thread, $this->actor($request), $d);

        return response()->json(['data' => ['id' => $ticket->id, 'reference' => $ticket->reference()]], 201);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->tickets->saveSettings($request->validate(['auto_close_days' => ['sometimes', 'integer', 'between:1,60'], 'email_company' => ['sometimes', 'boolean']]));

        return response()->json(['data' => ['auto_close_days' => $this->tickets->autoCloseDays(), 'email_company' => $this->tickets->emailsCompany()]]);
    }

    public function attachment(TicketAttachment $attachment): HttpResponse
    {
        return $attachment->response();
    }

    /** @return array{account_id: int, name: string, email: string} */
    private function actor(Request $request): array
    {
        $me = $request->attributes->get('platform_identity');

        return ['account_id' => $me->id, 'name' => $me->name, 'email' => $me->email];
    }

    /** @return array<string, mixed> */
    private function row(Ticket $t, $companies, $adminNames): array
    {
        return [
            'id' => $t->id, 'reference' => $t->reference(), 'subject' => $t->subject, 'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status,
            'company_id' => $t->tenant_id, 'company' => $companies[$t->tenant_id] ?? 'Deleted company', 'assignee_id' => $t->assignee_id, 'assignee' => $adminNames[$t->assignee_id] ?? null,
            'unread' => $t->unread_platform, 'created_at' => $t->created_at?->toIso8601String(), 'last_activity_at' => $t->last_activity_at?->toIso8601String(),
            'opened_by' => $t->opened_by_name,
        ];
    }
}
