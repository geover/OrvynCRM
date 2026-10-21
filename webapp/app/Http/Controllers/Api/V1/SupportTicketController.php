<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\Support\Tickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A company's support tickets. Administrators of the company only: every one of them sees all of the company's tickets and can write on them, so everybody knows.
 * A ticket is opened here (or from the "Report a problem" button), answered by the platform, and can be closed or reopened by the company.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly Tickets $tickets) {}

    /** What the sidebar badge and an open page ask every few seconds: how many tickets have news, and a signature that changes when any ticket does. */
    public function poll(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $mine = fn () => Ticket::forTenant((string) tenant('id'));

        return response()->json(['unread' => $mine()->where('unread_tenant', true)->count(), 'sig' => $mine()->count().'|'.$mine()->max('updated_at')]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $rows = Ticket::forTenant((string) tenant('id'))->withCount(['replies as replies_count' => fn ($q) => $q->where('kind', 'reply')])->orderByDesc('last_activity_at')->limit(300)->get();

        return response()->json([
            'data' => $rows->map(fn (Ticket $t) => [
                'id' => $t->id, 'reference' => $t->reference(), 'subject' => $t->subject, 'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status,
                'opened_by' => $t->opened_by_name, 'created_at' => $t->created_at?->toIso8601String(), 'last_activity_at' => $t->last_activity_at?->toIso8601String(),
                'unread' => $t->unread_tenant, 'replies' => $t->replies_count,
            ])->values(),
            'unread' => $rows->where('unread_tenant', true)->count(), 'categories' => Ticket::CATEGORIES,
        ]);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $ticket->unread_tenant && $ticket->forceFill(['unread_tenant' => false])->save();           // opening it reads it

        return response()->json(['data' => $this->tickets->forCompany($ticket)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $d = $request->validate([
            'subject' => ['required', 'string', 'max:200'], 'category' => ['required', Rule::in(array_keys(Ticket::CATEGORIES))], 'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'context' => ['nullable', 'array'], 'context.*' => ['nullable', 'string', 'max:400'],
        ] + $this->bodyRules());
        $files = $this->files($request);
        $this->mustSaySomething($d['body'] ?? null, $files);

        $ticket = $this->tickets->open((string) tenant('id'), $this->sender($request), [
            'subject' => $d['subject'], 'body' => $d['body'] ?? null, 'category' => $d['category'], 'priority' => $d['priority'],
            'context' => $this->context($request, $d['context'] ?? []),
        ], $files);

        return response()->json(['data' => $this->tickets->forCompany($ticket)], 201);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $d = $request->validate($this->bodyRules());
        $files = $this->files($request);
        $this->mustSaySomething($d['body'] ?? null, $files);

        $this->tickets->reply($ticket, 'tenant', $this->sender($request), $d['body'] ?? null, $files);

        return response()->json(['data' => $this->tickets->forCompany($ticket->refresh())], 201);
    }

    public function close(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $this->tickets->setStatus($ticket, 'closed', 'tenant', $this->sender($request));

        return response()->json(['data' => $this->tickets->forCompany($ticket->refresh())]);
    }

    public function reopen(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $this->tickets->reopen($ticket, $this->sender($request));

        return response()->json(['data' => $this->tickets->forCompany($ticket->refresh())]);
    }

    /** @return array<string, mixed> */
    private function bodyRules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:20000', 'required_without:attachments'],
            'attachments' => ['sometimes', 'array', 'max:'.TicketAttachment::MAX_PER_REPLY],
            'attachments.*' => ['file', 'mimes:'.implode(',', array_keys(TicketAttachment::TYPES)), 'max:'.TicketAttachment::MAX_KILOBYTES],
        ];
    }

    /** @return list<UploadedFile> */
    private function files(Request $request): array
    {
        return array_values(array_filter((array) $request->file('attachments'), fn ($f) => $f instanceof UploadedFile));
    }

    /** A line made only of empty formatting (an empty editor) and no file says nothing. */
    private function mustSaySomething(?string $body, array $files): void
    {
        if ($files === [] && trim(html_entity_decode(strip_tags((string) $body), ENT_QUOTES | ENT_HTML5)) === '') {
            throw ValidationException::withMessages(['body' => 'Write what happened, or attach a screenshot.']);
        }
    }

    /**
     * What helps the platform understand a report: the page, the browser, the screen and who wrote it. Only short text, only known keys.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, string>
     */
    private function context(Request $request, array $given): array
    {
        $out = array_intersect_key(array_map(fn ($v) => (string) $v, $given), array_flip(['page', 'browser', 'screen', 'language']));
        $out['role'] = $request->user()->role;
        $out['reported_at'] = now()->toIso8601String();

        return $out;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(tenancy()->initialized && $request->user()->isAdmin() && ! $request->user()->is_platform_support, 403);
    }

    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        $this->authorizeAdmin($request);
        abort_unless($ticket->tenant_id === tenant('id'), 404);                                   // only this company's tickets exist for it
    }

    /** @return array{account_id: int|null, name: string, email: string} */
    private function sender(Request $request): array
    {
        return ['account_id' => $request->user()->central_user_id, 'name' => $request->user()->name, 'email' => $request->user()->email];
    }
}
