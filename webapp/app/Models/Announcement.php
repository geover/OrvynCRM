<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A platform announcement (update, news, improvement, fix, maintenance notice). Central: written once on the platform,
 * read by everybody: on the sign-in page and inside every company.
 *
 * @property string $status draft | scheduled | live | expired
 */
class Announcement extends Model
{
    use CentralConnection;

    public const CATEGORIES = ['update', 'news', 'improvement', 'fix', 'maintenance'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime', 'expires_at' => 'datetime',
            'pinned' => 'boolean', 'show_on_login' => 'boolean', 'show_in_app' => 'boolean', 'archived_at' => 'datetime',
        ];
    }

    /** Published, already due, and not past its end date. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->whereNotNull('published_at')->where('published_at', '<=', now())
            ->where(fn (Builder $w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id');
    }

    public function getStatusAttribute(): string
    {
        if (! $this->published_at) {
            return 'draft';
        }
        if ($this->published_at->isFuture()) {
            return 'scheduled';
        }

        return $this->expires_at && $this->expires_at->isPast() ? 'expired' : 'live';
    }

    /**
     * What a reader gets (never the admin-only fields).
     *
     * @return array{id: int, title: string, body: string, category: string, pinned: bool, published_at: ?string}
     */
    public function toReader(): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'body' => $this->body, 'category' => $this->category,
            'pinned' => $this->pinned, 'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
