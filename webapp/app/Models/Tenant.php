<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A company. Lives in the central database; everything the company owns lives in its own database.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status active | suspended | closed
 * @property Carbon|null $last_active_at
 * @property Carbon|null $inactive_since
 * @property int $tier licence tier 1 to 5 (config/plans.php)
 * @property Carbon|null $trial_ends_at null: no trial (never forced to give billing details)
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    /** Real columns on the central `tenants` table; anything else goes to the JSON `data` column. */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'slug', 'status', 'plan', 'max_users', 'max_data_objects', 'max_automations_per_object', 'last_active_at', 'inactive_since', 'closed_at', 'status_note', 'tier', 'trial_ends_at', 'trial_notice_stage'];
    }

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'inactive_since' => 'datetime', 'closed_at' => 'datetime', 'trial_ends_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(AccountUser::class, 'memberships', 'tenant_id', 'account_user_id')->withTimestamps();
    }
}
