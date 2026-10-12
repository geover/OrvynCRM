<?php

namespace Tests\Feature\Tenancy;

use App\Models\Contact;
use App\Models\Membership;
use App\Models\PermissionTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

class ProvisioningTest extends TenantTestCase
{
    public function test_a_company_gets_its_own_database_with_defaults_and_an_administrator(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');

        $this->assertTrue(is_file($this->tenantDir.DIRECTORY_SEPARATOR."tenant{$acme->id}.sqlite"));
        $this->assertSame('acme', $acme->slug);
        $this->assertTrue(Membership::where('tenant_id', $acme->id)->exists());

        $acme->run(function () {
            $this->assertTrue(PermissionTemplate::where('name', 'Administrator')->exists());     // seeded by TenantDatabaseSeeder
            $owner = User::where('email', 'owner@acme.test')->firstOrFail();
            $this->assertSame('admin', $owner->role);
            $this->assertTrue($owner->isAdmin());
            $this->assertNotNull($owner->central_user_id);
        });
        $this->assertFalse(tenancy()->initialized, 'run() must hand the connection back');
    }

    public function test_companies_do_not_see_each_others_data_settings_or_cache(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');

        $a->run(function () {
            Contact::create(['first_name' => 'Only', 'last_name' => 'InAlpha', 'email' => 'x@alpha.test']);
            Setting::put('app.name', 'Alpha Co');
            cache()->put('shared-key', 'alpha');
        });

        $b->run(function () {
            $this->assertSame(0, Contact::count());
            $this->assertSame(config('app.name'), Setting::get('app.name', config('app.name')));
            $this->assertNull(cache()->get('shared-key'));
            Setting::put('app.name', 'Beta Co');
            cache()->put('shared-key', 'beta');
        });

        $a->run(function () {
            $this->assertSame(1, Contact::count());
            $this->assertSame('Alpha Co', Setting::get('app.name'));
            $this->assertSame('alpha', cache()->get('shared-key'));
        });
    }

    public function test_slugs_must_be_unique_and_not_reserved(): void
    {
        $this->company('Acme', 'o@acme.test');

        foreach (['Acme', 'admin', 'API'] as $name) {
            try {
                $this->company($name, 'x@y.test');
                $this->fail("{$name} should have been refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('slug', $e->errors());
            }
        }
    }
}
