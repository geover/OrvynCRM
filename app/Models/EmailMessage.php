<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'to' => 'array', 'cc' => 'array', 'bcc' => 'array', 'attachments' => 'array',
            'scheduled_at' => 'datetime', 'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // the first 12 characters are the company id: the tracking pixel has no session (see ResolvePublicTenant)
        static::creating(fn (self $m) => $m->tracker_id ??= (tenant('id') ?? '').Str::ulid());
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', 'queued')
            ->where(fn ($w) => $w->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));
    }
}
