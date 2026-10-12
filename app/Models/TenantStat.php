<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** The latest totals for one company (contacts, automations, database size...), refreshed hourly. */
class TenantStat extends Model
{
    use CentralConnection;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['top_automations' => 'array', 'counts' => 'array', 'object_breakdown' => 'array', 'collected_at' => 'datetime', 'db_mb' => 'float'];
    }
}
