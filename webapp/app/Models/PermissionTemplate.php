<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermissionTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_admin' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
