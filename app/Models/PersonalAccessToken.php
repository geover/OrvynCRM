<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * API tokens live in the central database so a bearer token can tell us which company it belongs to before any
 * company database is open. `tokenable` is the person's profile INSIDE that company, and profile ids repeat across
 * companies, so while a company is active every query is scoped to it.
 */
class PersonalAccessToken extends SanctumToken
{
    use CentralConnection;

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'tenant_id', 'account_user_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if ($id = tenant('id')) {
                $query->where($query->getModel()->getTable().'.tenant_id', $id);
            }
        });
        static::creating(function (self $token) {
            $token->tenant_id ??= tenant('id');
            // remember the central identity, so a password reset can revoke the token in every company (a direct query:
            // lazy-loading the morph relation would trip the dev-mode lazy-loading guard)
            if ($token->account_user_id === null && $token->tokenable_type && $token->tokenable_id) {
                $token->account_user_id = $token->tokenable_type::query()->whereKey($token->tokenable_id)->value('central_user_id');
            }
        });
    }
}
