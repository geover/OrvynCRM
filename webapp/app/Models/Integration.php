<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'settings' => 'array',
            'is_enabled' => 'boolean', 'last_synced_at' => 'datetime',
        ];
    }

    public static function for(string $provider): ?self
    {
        return static::where('provider', $provider)->where('is_enabled', true)->first();
    }
}
