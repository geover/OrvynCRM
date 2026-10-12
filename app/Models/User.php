<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['central_user_id', 'name', 'email', 'password', 'role', 'status', 'timezone', 'signature', 'preferences', 'permission_template_id', 'contact_id', 'caller_id', 'is_platform_support'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** Mirror DB defaults so freshly created instances never trip missing-attribute checks. */
    protected $attributes = ['central_user_id' => null, 'role' => 'user', 'status' => 'active', 'timezone' => 'UTC', 'permission_template_id' => null, 'contact_id' => null, 'signature' => null, 'caller_id' => null, 'preferences' => null, 'is_platform_support' => false];

    /**
     * Always the ACTIVE connection (the open company's database). Without this, a relation from a central model (an API
     * token's `tokenable`) would make Eloquent load the profile on the central connection, where it does not exist.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? DB::getDefaultConnection();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'is_platform_support' => 'boolean',
        ];
    }

    /** The company's own people: platform administrators' support profiles are not company users and are never listed. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('is_platform_support', false);
    }

    public function permissionTemplate(): BelongsTo
    {
        return $this->belongsTo(PermissionTemplate::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || (bool) $this->permissionTemplate?->is_admin;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Check a permission like "contacts.edit" or "data_objects.12.view" against the user's template. */
    public function hasPermission(string $ability): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return (bool) data_get($this->permissionTemplate?->permissions ?? [], $ability)
            || in_array(last(explode('.', $ability)), (array) data_get($this->permissionTemplate?->permissions ?? [], implode('.', array_slice(explode('.', $ability), 0, -1)), []), true);
    }
}
