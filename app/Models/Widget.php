<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Widget extends Model
{
    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(SavedQuery::class);
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
