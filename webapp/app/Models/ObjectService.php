<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** A background service switched on or off for one data object of a company. No row means "on". */
class ObjectService extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'changed_at' => 'datetime'];
    }
}
