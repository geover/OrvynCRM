<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * "Somebody used this company": a person opened a page, signed in or changed something, or a visitor sent one of its forms. It is
 * what lets the platform tell a quiet company from an abandoned one (see InactiveCompanies). Background polling, the company's own
 * automations and platform support visits do not count: only people do. Written to the central tenants row at most every ten minutes.
 */
class CompanyActivity
{
    private const EVERY_SECONDS = 600;

    public function touch(?Tenant $tenant = null): void
    {
        $tenant ??= tenancy()->initialized ? tenant() : null;
        if (! $tenant instanceof Tenant) {
            return;
        }

        $id = $tenant->getTenantKey();
        if (! Cache::add("company-active:{$id}", 1, self::EVERY_SECONDS)) {
            return;
        }
        Tenant::whereKey($id)->update(['last_active_at' => now(), 'inactive_since' => null]);       // used again: no longer inactive
    }
}
