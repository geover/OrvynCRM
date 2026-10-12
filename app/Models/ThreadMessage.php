<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** One message in a thread, with who wrote it: a company administrator ("tenant") or a platform administrator ("platform"). */
class ThreadMessage extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ThreadAttachment::class, 'message_id');
    }

    /**
     * What a viewer gets. Needs `attachments` loaded (with('attachments')). `$side` decides which route serves the images:
     * a company reads them through its own session, the platform through the platform area.
     *
     * @return array<string, mixed>
     */
    public function toArrayForViewer(string $side = 'tenant'): array
    {
        return [
            'id' => $this->id, 'from' => $this->sender_type, 'name' => $this->sender_name, 'email' => $this->sender_email,
            'body' => $this->body, 'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => $this->attachments->map(fn (ThreadAttachment $a) => [
                'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
                'url' => $side === 'platform' ? "/platform/attachments/{$a->id}" : "/files/thread-attachments/{$a->id}",
            ])->values(),
        ];
    }
}
