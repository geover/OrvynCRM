<?php

namespace Tests;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

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

    /**
     * Let the following API calls through as this person: the API only takes a token (the web pages use the session), so ask for one for the
     * company they belong to and send it with every request from here on.
     */
    protected function bearerFor(string $email, string $password): void
    {
        $this->defaultHeaders = [];                 // never carry the previous person's token over
        if (AccountUser::where('email', $email)->value('is_platform_admin')) {
            return;                                 // a platform administrator uses the platform pages, not a company's API
        }
        foreach (Tenant::pluck('slug') as $slug) {
            $this->app['auth']->forgetGuards();
            $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $password, 'company' => $slug])->json('token');
            if ($token) {
                $this->defaultHeaders = [];
                $this->withToken($token);
                $this->app['auth']->forgetGuards();

                return;
            }
        }
    }

    /** Fake the outgoing HTTP calls: a later call replaces the earlier fake (Laravel keeps the first matching one otherwise). */
    protected function fakeHttp(array|\Closure|null $stubs = null): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    protected function identity(string $email): AccountUser
    {
        return AccountUser::where('email', $email)->firstOrFail();
    }
}
