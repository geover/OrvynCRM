<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A problem a company's administrators reported and the platform's administrators work on. Every administrator of the company sees it.
 * Status: open (the platform's turn), waiting (the company's turn), resolved (the platform thinks it is fixed), closed.
 */
class Ticket extends Model
{
    use CentralConnection;

    public const STATUSES = ['open', 'waiting', 'resolved', 'closed'];

    public const CATEGORIES = ['bug' => 'Something is not working', 'question' => 'A question', 'request' => 'A request', 'billing' => 'Billing', 'other' => 'Something else'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array', 'unread_platform' => 'boolean', 'unread_tenant' => 'boolean',
            'last_activity_at' => 'datetime', 'first_response_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Ticket $ticket) {                                                  // the files go with the ticket
            TicketAttachment::whereIn('reply_id', $ticket->replies()->pluck('id'))->get()->each->deleteFile();
            TicketAttachment::whereIn('reply_id', $ticket->replies()->pluck('id'))->delete();
            $ticket->replies()->delete();
        });
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->orderBy('id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AccountUser::class, 'assignee_id');
    }

    /** "T-0042": what both sides call it. */
    public function reference(): string
    {
        return 'T-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['resolved', 'closed'], true);
    }

    /** What a company sees: its own tickets. */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Waiting for the platform: open, and not yet looked at since the company last wrote. */
    public function scopeNeedingPlatform(Builder $query): Builder
    {
        return $query->where('status', 'open')->where('unread_platform', true);
    }
}
