<?php

namespace App\Support\Tenancy;

use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/** SQLite company databases (development, tests, tiny installs): one file per company under TENANCY_SQLITE_PATH. */
class StorageSqliteManager implements TenantDatabaseManager
{
    public static function path(string $name): string
    {
        $dir = config('tenancy.database.sqlite_path') ?: storage_path('tenants');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return rtrim($dir, '/\\').DIRECTORY_SEPARATOR.(str_ends_with($name, '.sqlite') ? $name : $name.'.sqlite');
    }

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return file_put_contents(self::path($tenant->database()->getName()), '') !== false;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $file = self::path($tenant->database()->getName());

        return is_file($file) ? unlink($file) : true;
    }

    public function databaseExists(string $name): bool
    {
        return is_file(self::path($name));
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = self::path($databaseName);

        return $baseConfig;
    }

    public function setConnection(string $connection): void
    {
        //
    }
}
