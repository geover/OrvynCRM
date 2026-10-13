<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Someone who can sign in: one row per person, however many companies they belong to.
 * Credentials live only here; each company keeps a profile row (App\Models\User) linked by central_user_id.
 */
class AccountUser extends Authenticatable
{
    use CentralConnection, Notifiable;

    protected $table = 'account_users';

    protected $fillable = ['name', 'email', 'password', 'is_platform_admin', 'status', 'notify_requests', 'notify_messages', 'notify_automation_failures', 'notify_inactive_companies', 'notify_email'];

    protected $hidden = ['password'];

    protected $attributes = ['is_platform_admin' => false, 'status' => 'active', 'notify_requests' => true, 'notify_messages' => true];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'is_platform_admin' => 'boolean', 'notify_requests' => 'boolean', 'notify_messages' => 'boolean', 'notify_automation_failures' => 'boolean', 'notify_inactive_companies' => 'boolean'];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'memberships', 'account_user_id', 'tenant_id')->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Emails are the identity: always compared lower-case. */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }
}
