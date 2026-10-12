<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataObjectField extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array', 'is_required' => 'boolean', 'is_indexed' => 'boolean',
            'show_in_list' => 'boolean', 'contact_specific' => 'boolean',
        ];
    }

    public function dataObject(): BelongsTo
    {
        return $this->belongsTo(DataObject::class);
    }
}
