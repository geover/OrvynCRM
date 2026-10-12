<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Form extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $f) => $f->uuid ??= (string) Str::uuid());
        // the public page, embed script and submit endpoint carry no session: the uuid must lead to this company
        static::created(fn (self $f) => PublicLink::register(PublicLink::FORM, $f->uuid));
        static::deleted(fn (self $f) => PublicLink::forget($f->uuid));
    }

    public function dataObject(): BelongsTo
    {
        return $this->belongsTo(DataObject::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
