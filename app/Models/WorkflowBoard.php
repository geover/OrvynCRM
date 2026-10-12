<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowBoard extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['display_fields' => 'array', 'colors' => 'array'];
    }

    public function dataObject(): BelongsTo
    {
        return $this->belongsTo(DataObject::class);
    }
}
