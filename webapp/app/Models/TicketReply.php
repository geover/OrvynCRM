<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** One line of a ticket: a reply by either side, a platform-only note, or a note of what changed (status, assignee, priority). */
class TicketReply extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'reply_id');
    }

    /**
     * What a viewer gets. Needs `attachments` loaded. `$side` decides which route serves the files: a company reads them through its own session, the platform through the platform area.
     *
     * @return array<string, mixed>
     */
    public function forViewer(string $side): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'from' => $this->side, 'name' => $this->name, 'email' => $side === 'platform' ? $this->email : null,
            'body' => $this->body, 'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => $this->attachments->map(fn (TicketAttachment $a) => [
                'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size, 'image' => str_starts_with($a->mime, 'image/'),
                'url' => $side === 'platform' ? "/platform/tickets/attachments/{$a->id}" : "/files/ticket-attachments/{$a->id}",
            ])->values(),
        ];
    }
}
