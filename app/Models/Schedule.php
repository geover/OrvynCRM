<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['contact_specific' => 'boolean'];
    }

    public function dataObject(): BelongsTo
    {
        return $this->belongsTo(DataObject::class);
    }
}
