<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PriceList extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'is_locked' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->token ??= Str::random(32));
        static::created(fn (self $p) => PublicLink::register(PublicLink::PRICE_LIST, $p->token));
        static::deleted(fn (self $p) => PublicLink::forget($p->token));
    }
}
