<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Gives every company its own cache key space by prefixing keys, which works with every cache store
 * (the package's tag-based bootstrapper needs a taggable store, and the database store is not one).
 */
class CachePrefixBootstrapper implements TenancyBootstrapper
{
    private ?string $original = null;

    public function bootstrap(Tenant $tenant): void
    {
        $this->original ??= (string) config('cache.prefix');
        $this->swap($this->original.'t'.$tenant->getTenantKey().':');
    }

    public function revert(): void
    {
        if ($this->original !== null) {
            $this->swap($this->original);
        }
    }

    private function swap(string $prefix): void
    {
        config(['cache.prefix' => $prefix]);
        foreach (array_keys(config('cache.stores', [])) as $store) {
            Cache::forgetDriver($store);       // stores read the prefix when they are built
        }
        Cache::forgetDriver();
        // components that took the cache from the container (queue overlap locks, ...) must not keep the previous company's repository
        app()->forgetInstance('cache.store');
    }
}
