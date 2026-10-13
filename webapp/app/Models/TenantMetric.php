<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** One company, one hour: what it did and what its background work cost. See the platform dashboard. */
class TenantMetric extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hour' => 'datetime'];
    }

    /**
     * Add to the counters of a company's hour (creating the row on first use; safe when two workers do it at once).
     *
     * @param  array<string, int>  $by
     */
    public static function add(string $tenantId, \DateTimeInterface $hour, array $by): void
    {
        $row = ['tenant_id' => $tenantId, 'hour' => $hour];
        if (static::query()->where($row)->incrementEach($by) > 0) {
            return;
        }
        try {
            static::query()->create($row + $by);
        } catch (UniqueConstraintViolationException) {
            static::query()->where($row)->incrementEach($by);       // the other worker created it first
        }
    }

    /**
     * Set absolute values for a company's hour (the hourly collector).
     *
     * @param  array<string, int>  $values
     */
    public static function put(string $tenantId, \DateTimeInterface $hour, array $values): void
    {
        static::query()->updateOrCreate(['tenant_id' => $tenantId, 'hour' => $hour], $values);
    }
}
