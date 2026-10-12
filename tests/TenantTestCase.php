<?php

namespace Tests;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/**
 * Base class for tests that need REAL companies: each company gets its own SQLite database file (created, migrated and
 * seeded by the same provisioning code production uses) and the application runs in multi-company mode.
 * Everything is removed again after the test.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
        $this->tenantDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orvyn-tenants-'.bin2hex(random_bytes(4));
        // the in-memory `array` cache ignores key prefixes; the database store (like file and Redis) honours them
        config(['app.single_database' => false, 'tenancy.database.sqlite_path' => $this->tenantDir, 'cache.default' => 'database']);
        app('cache')->forgetDriver();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $ids = Tenant::pluck('id');
        Tenant::all()->each->delete();            // the TenantDeleted pipeline removes the database files
        File::deleteDirectory($this->tenantDir);
        $ids->each(fn ($id) => File::deleteDirectory(base_path("storage/tenant{$id}")));       // and the company's storage folder, which would pile up run after run
        parent::tearDown();
    }

    /** A company with one administrator (who can sign in with $password). */
    protected function company(string $name, string $ownerEmail, string $password = 'Owner-Passw0rd-1'): Tenant
    {
        return app(TenantProvisioner::class)->createCompany($name, null, ['name' => "{$name} Owner", 'email' => $ownerEmail, 'password' => $password]);
    }

    protected function identity(string $email): AccountUser
    {
        return AccountUser::where('email', $email)->firstOrFail();
    }
}
