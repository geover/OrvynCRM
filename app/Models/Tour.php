<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tour extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['email_settings' => 'array', 'archived' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TourStop::class);
    }
}
