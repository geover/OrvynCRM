<?php

namespace App\Services\Support;

use App\Models\AccountUser;
use App\Models\MessageThread;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Tenancy\PlatformNotifier;
use App\Services\Tenancy\ThreadMailer;
use App\Support\NoticeMail;
use App\Support\SafeHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Support tickets. A company's administrators report a problem; the platform's administrators work on it; both sides write on it, and every administrator of the
 * company sees it. This holds the rules: who may do what to the status, what each write changes (whose turn it is, who has something unread) and who is told.
 *
 * @phpstan-type Sender array{account_id?: int|null, name: string, email: string}
 */
class Tickets
{
    public const AUTO_CLOSE_DAYS = 3;

    public function __construct(private readonly PlatformNotifier $notifier) {}

    // ---- the platform's choices (Platform > Tickets > Settings)

    public function autoCloseDays(): int
    {
        $d = (int) PlatformSetting::get('tickets.auto_close_days', (string) self::AUTO_CLOSE_DAYS);

        return $d >= 1 && $d <= 60 ? $d : self::AUTO_CLOSE_DAYS;
    }

    public function emailsCompany(): bool
    {
        return PlatformSetting::get('tickets.email_company') !== '0';
    }

    /** @param  array{auto_close_days?: int, email_company?: bool}  $d */
    public function saveSettings(array $d): void
    {
        isset($d['auto_close_days']) && PlatformSetting::put('tickets.auto_close_days', (string) min(60, max(1, (int) $d['auto_close_days'])));
        array_key_exists('email_company', $d) && PlatformSetting::put('tickets.email_company', ! empty($d['email_company']) ? '1' : '0');
    }

    // ---- writing

    /**
     * A company administrator reports a problem.
     *
     * @param  Sender  $sender
     * @param  array{subject: string, body?: ?string, category?: string, priority?: string, context?: array<string, mixed>}  $data
     * @param  list<UploadedFile>  $files
     */
    public function open(string $tenantId, array $sender, array $data, array $files = []): Ticket
    {
        $ticket = Ticket::create([
            'tenant_id' => $tenantId, 'subject' => trim($data['subject']), 'category' => $data['category'] ?? 'other', 'priority' => $data['priority'] ?? 'normal',
            'opened_by_account_id' => $sender['account_id'] ?? null, 'opened_by_name' => $sender['name'], 'opened_by_email' => $sender['email'],
            'context' => $data['context'] ?? null, 'unread_platform' => true, 'unread_tenant' => false, 'last_activity_at' => now(),
        ]);
        $reply = $this->write($ticket, 'reply', 'tenant', $sender, $data['body'] ?? null, $files);
        $this->tellPlatform($ticket, "{$sender['name']} ({$sender['email']}) opened a ticket", $reply);

        return $ticket;
    }

    /**
     * A reply, or (platform only) a note nobody outside the platform sees. A reply from the company brings a finished ticket back; a reply from the platform is the company's turn.
     *
     * @param  Sender  $sender
     * @param  list<UploadedFile>  $files
     */
    public function reply(Ticket $ticket, string $side, array $sender, ?string $body, array $files = [], bool $note = false): TicketReply
    {
        if ($note) {
            abort_unless($side === 'platform', 403);

            return tap($this->write($ticket, 'note', 'platform', $sender, $body, $files), fn () => $ticket->forceFill(['last_activity_at' => now()])->save());
        }

        $reply = $this->write($ticket, 'reply', $side, $sender, $body, $files);
        if ($side === 'tenant') {
            $this->reopenIfFinished($ticket, $sender);
            $ticket->forceFill(['status' => 'open', 'unread_platform' => true, 'unread_tenant' => false, 'last_activity_at' => now()])->save();
            $this->tellPlatform($ticket, "{$sender['name']} replied", $reply);
        } else {
            $ticket->forceFill([
                'status' => $ticket->isFinished() ? $ticket->status : 'waiting', 'unread_tenant' => true, 'last_activity_at' => now(), 'first_response_at' => $ticket->first_response_at ?? now(),
                'assignee_id' => $ticket->assignee_id ?? ($sender['account_id'] ?? null),                                  // whoever answers first takes it
            ])->save();
            $this->tellCompany($ticket, "{$ticket->reference()}: {$ticket->subject}", "{$sender['name']} from the platform replied:\n\n".$this->plain($reply->body), true);
        }

        return $reply;
    }

    /** Resolve or close (either side may close; only the platform resolves). @param  Sender  $actor */
    public function setStatus(Ticket $ticket, string $status, string $side, array $actor): void
    {
        abort_unless(in_array($status, ['open', 'waiting', 'resolved', 'closed'], true), 422, 'Unknown status.');
        abort_if($side === 'tenant' && ! in_array($status, ['closed'], true), 403);
        if ($ticket->status === $status) {
            return;
        }
        $was = $ticket->status;
        $ticket->forceFill([
            'status' => $status, 'last_activity_at' => now(),
            'resolved_at' => $status === 'resolved' ? now() : ($status === 'closed' ? $ticket->resolved_at : null), 'closed_at' => $status === 'closed' ? now() : null,
            'unread_tenant' => $side === 'platform' ? true : $ticket->unread_tenant, 'unread_platform' => $side === 'tenant' ? true : $ticket->unread_platform,
        ])->save();
        $this->event($ticket, ($side === 'platform' ? 'The platform' : $actor['name']).' changed the status from '.$was.' to '.$status.'.', $actor);

        if ($side === 'platform' && $status === 'resolved') {
            $this->tellCompany($ticket, "{$ticket->reference()} is resolved: {$ticket->subject}", "{$actor['name']} from the platform marked this ticket as resolved. If it is not fixed, reply to it and it will be reopened. It closes by itself after {$this->autoCloseDays()} days.", true);
        }
        if ($side === 'tenant' && $status === 'closed') {
            $this->tellPlatform($ticket, "{$actor['name']} closed the ticket");
        }
    }

    /** The company brings a resolved or closed ticket back. @param  Sender  $actor */
    public function reopen(Ticket $ticket, array $actor): void
    {
        abort_unless($ticket->isFinished(), 422, 'This ticket is not closed.');
        $this->reopenIfFinished($ticket, $actor);
        $ticket->forceFill(['status' => 'open', 'unread_platform' => true, 'last_activity_at' => now()])->save();
        $this->tellPlatform($ticket, "{$actor['name']} reopened the ticket");
    }

    /** @param  Sender  $actor */
    public function assign(Ticket $ticket, ?int $accountId, array $actor): void
    {
        $ticket->forceFill(['assignee_id' => $accountId])->save();
        $to = $accountId ? (AccountUser::find($accountId)?->name ?? 'someone') : 'nobody';
        $this->event($ticket, "{$actor['name']} assigned the ticket to {$to}.", $actor, true);
    }

    /** @param  Sender  $actor */
    public function classify(Ticket $ticket, array $d, array $actor): void
    {
        foreach (['priority' => 'priority', 'category' => 'category'] as $key => $label) {
            if (isset($d[$key]) && $d[$key] !== $ticket->{$key}) {
                $old = $ticket->{$key};
                $ticket->forceFill([$key => $d[$key]])->save();
                $this->event($ticket, "{$actor['name']} changed the {$label} from {$old} to {$d[$key]}.", $actor, true);
            }
        }
    }

    /**
     * An administrator of the platform decides that a Messages conversation is an issue: it becomes a ticket with its history, the conversation says so, and the company is told.
     *
     * @param  Sender  $actor
     * @param  array{category?: string, priority?: string}  $d
     */
    public function fromThread(MessageThread $thread, array $actor, array $d = []): Ticket
    {
        abort_if($thread->ticket_id, 422, 'This conversation already became a ticket.');
        $ticket = Ticket::create([
            'tenant_id' => $thread->tenant_id, 'subject' => $thread->subject, 'category' => $d['category'] ?? 'other', 'priority' => $d['priority'] ?? 'normal',
            'opened_by_name' => $thread->started_by_name, 'opened_by_email' => $thread->started_by_email, 'thread_id' => $thread->id,
            'unread_platform' => false, 'unread_tenant' => false, 'last_activity_at' => now(), 'first_response_at' => now(), 'assignee_id' => $actor['account_id'] ?? null,
        ]);
        foreach ($thread->messages()->with('attachments')->get() as $m) {            // the conversation so far, line by line, with its pictures
            $line = TicketReply::create([
                'ticket_id' => $ticket->id, 'kind' => 'reply', 'side' => $m->sender_type === 'platform' ? 'platform' : 'tenant', 'account_id' => $m->sender_account_id,
                'name' => $m->sender_name, 'email' => $m->sender_email, 'body' => (string) $m->body, 'created_at' => $m->created_at,
            ]);
            foreach ($m->attachments as $a) {
                if (! Storage::disk('platform')->exists($a->path)) {
                    continue;
                }
                $copy = 'tickets/'.bin2hex(random_bytes(16)).'.'.pathinfo($a->path, PATHINFO_EXTENSION);
                Storage::disk('platform')->copy($a->path, $copy);                  // a copy: deleting the conversation must not take the ticket's pictures with it
                TicketAttachment::create(['reply_id' => $line->id, 'path' => $copy, 'original_name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size]);
            }
        }
        $this->event($ticket, "{$actor['name']} turned the conversation “{$thread->subject}” into this ticket.", $actor);
        $thread->forceFill(['ticket_id' => $ticket->id])->save();

        $message = $thread->post('platform', $actor, "This conversation is now ticket {$ticket->reference()}, so it can be followed until it is solved. You will find it under Company settings > Support tickets: ".url("/tickets?ticket={$ticket->id}"));
        app(ThreadMailer::class)->toCompany($thread, $message);

        return $ticket;
    }

    /** Resolved tickets nobody wrote on for the chosen number of days are closed. @return int how many */
    public function autoClose(): int
    {
        $closed = 0;
        $cutoff = now()->subDays($this->autoCloseDays());
        Ticket::where('status', 'resolved')->where('resolved_at', '<=', $cutoff)->where('last_activity_at', '<=', $cutoff)->each(function (Ticket $t) use (&$closed) {
            $t->forceFill(['status' => 'closed', 'closed_at' => now(), 'unread_tenant' => true])->save();
            $this->event($t, "Closed by itself after {$this->autoCloseDays()} days without a reply.", ['name' => 'System', 'email' => '']);
            $this->tellCompany($t, "{$t->reference()} was closed: {$t->subject}", "It was resolved and nobody replied for {$this->autoCloseDays()} days, so it is closed. You can reopen it any time.", false);
            $closed++;
        });

        return $closed;
    }

    // ---- reading

    /** What a company may see of a ticket: no platform notes, no addresses of the platform's people. @return array<string, mixed> */
    public function forCompany(Ticket $t): array
    {
        return [
            'id' => $t->id, 'reference' => $t->reference(), 'subject' => $t->subject, 'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status,
            'opened_by' => $t->opened_by_name, 'created_at' => $t->created_at?->toIso8601String(), 'last_activity_at' => $t->last_activity_at?->toIso8601String(),
            'unread' => $t->unread_tenant, 'resolved_at' => $t->resolved_at?->toIso8601String(), 'auto_close_days' => $this->autoCloseDays(), 'thread_id' => $t->thread_id,
            'replies' => $t->replies()->where('kind', '!=', 'note')->where('side', '!=', 'internal')->with('attachments')->get()->map->forViewer('tenant')->values(),
        ];
    }

    // ---- inside

    /** @param  Sender  $sender @param  list<UploadedFile>  $files */
    private function write(Ticket $ticket, string $kind, string $side, array $sender, ?string $body, array $files): TicketReply
    {
        $reply = TicketReply::create([
            'ticket_id' => $ticket->id, 'kind' => $kind, 'side' => $side, 'account_id' => $sender['account_id'] ?? null, 'name' => $sender['name'], 'email' => $sender['email'] ?? null,
            'body' => SafeHtml::clean($body) ?? '', 'created_at' => now(),
        ]);
        foreach ($files as $file) {
            $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $mime = TicketAttachment::TYPES[$ext] ?? null;
            if (! $mime) {
                continue;                                                       // validation already allows only these; never store anything else
            }
            TicketAttachment::create([
                'reply_id' => $reply->id, 'path' => $file->storeAs('tickets', bin2hex(random_bytes(16)).'.'.$ext, 'platform'),
                'original_name' => str($file->getClientOriginalName())->limit(120, '')->toString(), 'mime' => $mime, 'size' => $file->getSize(),
            ]);
        }

        return $reply;
    }

    /** @param  Sender  $actor */
    /** An internal event (owner, priority, category) is for the platform only; the company sees the ones that matter to it. */
    private function event(Ticket $ticket, string $text, array $actor, bool $internal = false): void
    {
        TicketReply::create(['ticket_id' => $ticket->id, 'kind' => 'event', 'side' => $internal ? 'internal' : 'system', 'account_id' => $actor['account_id'] ?? null, 'name' => $actor['name'], 'email' => $actor['email'] ?? null, 'body' => $text, 'created_at' => now()]);
    }

    /** @param  Sender  $actor */
    private function reopenIfFinished(Ticket $ticket, array $actor): void
    {
        if ($ticket->isFinished()) {
            $ticket->forceFill(['reopened_count' => $ticket->reopened_count + 1, 'resolved_at' => null, 'closed_at' => null])->save();
            $this->event($ticket, "{$actor['name']} reopened the ticket.", $actor);
        }
    }

    private function plain(?string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>'], "\n", (string) $html)), ENT_QUOTES | ENT_HTML5));
    }

    private function tellPlatform(Ticket $ticket, string $what, ?TicketReply $reply = null): void
    {
        $company = Tenant::find($ticket->tenant_id);
        $text = "{$what}.\n\n{$ticket->reference()}: {$ticket->subject}\nCompany: {$company?->name}\nPriority: {$ticket->priority}".($reply ? "\n\n".$this->plain($reply->body) : '')."\n\nOpen it under Platform > Tickets: ".url("/platform/tickets?ticket={$ticket->id}");
        $this->notifier->mail(PlatformNotifier::TICKETS, "[{$company?->name}] {$ticket->reference()}: {$ticket->subject}", $text, [$ticket->opened_by_email, $ticket->opened_by_name]);
    }

    /** Every administrator of the company gets a notice in the app, and an e-mail when the platform allows it and `$email` is true. */
    private function tellCompany(Ticket $ticket, string $title, string $text, bool $email): void
    {
        $tenant = Tenant::find($ticket->tenant_id);
        if (! $tenant || ! $tenant->isActive()) {
            return;
        }
        $url = '/tickets?ticket='.$ticket->id;
        $admins = $tenant->run(function () use ($title, $text, $url) {
            $admins = User::listed()->with('permissionTemplate')->where('status', 'active')->get()->filter(fn (User $u) => $u->isAdmin())->values();
            $admins->each(fn (User $a) => DB::table('notifications_feed')->insert(['user_id' => $a->id, 'title' => $title, 'body' => mb_substr($text, 0, 240), 'url' => $url, 'created_at' => now(), 'updated_at' => now()]));

            return $admins->map(fn (User $a) => ['name' => $a->name, 'email' => $a->email])->all();
        });
        if ($email && $this->emailsCompany()) {
            foreach ($admins as $a) {
                NoticeMail::send($a['email'], $title, ["Hi {$a['name']},", ...array_values(array_filter(preg_split('/\n{2,}/', $text) ?: []))], ['Open the ticket', url($url)]);
            }
        }
    }
}
