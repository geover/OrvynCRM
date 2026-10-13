<?php

namespace App\Models;

use App\Support\FullText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Contact extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'addresses' => 'array',
            'custom_fields' => 'array',
            'emails' => 'array',
            'phones' => 'array',
            'birthday' => 'date',
            'email_opt_out' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** Emails are stored trimmed + lower-cased so look-ups, duplicate checks and unique rules behave the same on every DB engine. */
    protected function email(): Attribute
    {
        return Attribute::set(fn ($v) => filled($v) ? mb_strtolower(trim($v)) : null);
    }

    protected function emailSecondary(): Attribute
    {
        return Attribute::set(fn ($v) => filled($v) ? mb_strtolower(trim($v)) : null);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('created_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ContactAttachment::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(ContactRelationship::class);
    }

    public function dataObjectRecords(): BelongsToMany
    {
        return $this->belongsToMany(DataObjectRecord::class, 'data_object_record_contact', 'contact_id', 'record_id')
            ->withPivot(['relationship_id', 'contact_values'])->withTimestamps();
    }

    public function getFullNameAttribute(): string
    {
        // getAttributes() so a partial SELECT (name columns only) never trips missing-attribute checks
        $attrs = $this->getAttributes();

        return trim(($attrs['first_name'] ?? '').' '.($attrs['last_name'] ?? '')) ?: ($attrs['email'] ?? 'Contact #'.($attrs['id'] ?? ''));
    }

    /**
     * Search that uses the FULLTEXT index on MySQL/MariaDB (instead of Macanta's multi-table LIKE scans)
     * and falls back to prefix LIKE on other drivers.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        if ($boolean = FullText::booleanQuery($term)) {
            return $query->whereRaw('MATCH(first_name, last_name, email, company_name, phone) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
        }

        // every word must start one of the searchable columns, so "Jane Doe" finds first_name=Jane + last_name=Doe
        foreach (preg_split('/\s+/', $term) as $word) {
            $like = addcslashes($word, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('company_name', 'like', $like)
                ->orWhere('phone', 'like', $like));
        }

        return $query;
    }

    public function scopeWithTag(Builder $query, int|string $tag): Builder
    {
        return $query->whereIn('id', fn ($q) => $q->select('contact_id')->from('contact_tag')
            ->whereIn('tag_id', fn ($t) => $t->select('id')->from('tags')->where(is_numeric($tag) ? 'id' : 'name', $tag)));
    }
}
