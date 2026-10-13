<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
