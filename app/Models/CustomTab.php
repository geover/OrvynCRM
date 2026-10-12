<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomTab extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
