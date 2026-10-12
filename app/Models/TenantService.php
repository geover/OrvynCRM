<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** A company's switch (and last-run record) for one background service. No row means "on, never ran". */
class TenantService extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'last_run_at' => 'datetime', 'changed_at' => 'datetime'];
    }
}
