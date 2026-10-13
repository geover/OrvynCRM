<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\Membership;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Tests\TenantTestCase;

class PlatformAreaTest extends TenantTestCase
{
    private function platformAdmin(): AccountUser
    {
        return AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
    }

    public function test_only_platform_administrators_reach_the_platform_area(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->get('/platform')->assertRedirect('/login');                                                    // not signed in

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertRedirect('/');
        $this->get('/platform')->assertForbidden();                                                           // an ordinary company administrator
        $this->postJson('/platform/companies', ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(1, Tenant::count());

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1'])->assertRedirect('/platform');
        $this->get('/platform')->assertOk()->assertSee('Acme');
    }

    public function test_a_platform_administrator_creates_a_company_with_its_own_database_and_owner(): void
    {
        $this->platformAdmin();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->post('/platform/companies', [
            'name' => 'Fresh Co', 'owner_name' => 'Fiona Fresh', 'owner_email' => 'fiona@fresh.test', 'owner_password' => 'Fiona-Passw0rd-12',
        ])->assertRedirect()->assertSessionHas('success');

        $fresh = Tenant::where('slug', 'fresh-co')->firstOrFail();
        $this->assertTrue(is_file($this->tenantDir.DIRECTORY_SEPARATOR."tenant{$fresh->id}.sqlite"));
        $this->assertTrue(Membership::where('tenant_id', $fresh->id)->exists());
        $fresh->run(fn () => $this->assertSame('admin', User::where('email', 'fiona@fresh.test')->firstOrFail()->role));

        // refused: taken address, bad address, weak owner password
        $this->post('/platform/companies', ['name' => 'Fresh Co', 'owner_name' => 'x', 'owner_email' => 'x@x.test'])->assertSessionHasErrors('slug');
        $this->post('/platform/companies', ['name' => 'Odd', 'slug' => 'Bad Slug!', 'owner_name' => 'x', 'owner_email' => 'x@x.test'])->assertSessionHasErrors('slug');
        $this->post('/platform/companies', ['name' => 'Weak', 'owner_name' => 'x', 'owner_email' => 'w@x.test', 'owner_password' => 'weak'])->assertSessionHasErrors('owner_password');
        $this->assertSame(1, Tenant::count());
    }

    public function test_suspending_a_company_locks_everyone_out_and_reactivating_restores_access(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->patch("/platform/companies/{$acme->id}", ['status' => 'suspended'])->assertRedirect();
        $this->assertSame('suspended', $acme->fresh()->status);

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertSessionHasErrors('email');   // no active company left
        $this->postJson('/api/v1/auth/token', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
        $this->patch("/platform/companies/{$acme->id}", ['status' => 'active'])->assertRedirect();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertRedirect('/');
    }

    public function test_opening_a_company_gives_support_access_as_an_administrator_and_going_back_closes_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $admin = $this->platformAdmin();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->post("/platform/companies/{$acme->id}/open")->assertRedirect('/');
        $this->assertSame($acme->id, session('tenant_id'));
        $acme->run(function () use ($admin) {
            $support = User::where('central_user_id', $admin->id)->firstOrFail();
            $this->assertSame('admin', $support->role);
        });
        $this->assertTrue(Membership::where('account_user_id', $admin->id)->doesntExist(), 'support access does not make the platform administrator a member');

        $this->get('/platform')->assertOk();                                                                 // back to the platform area
        $this->assertNull(session('tenant_id'));
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_platform_pages_never_carry_a_companys_look_or_stay_inside_a_company(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
        $this->post("/platform/companies/{$acme->id}/open")->assertRedirect();                 // support mode: Acme is open in this session
        $this->assertSame($acme->id, session('tenant_id'));

        $acme->run(function () {
            Setting::put('app.name', 'Acme Rocket CRM');
            Setting::put('app.tagline', 'Acme tagline');
            Setting::put('app.ui_colour', '#ff0000');
            Setting::put('app.timezone', 'Pacific/Auckland');
        });

        // every platform page (not only the companies list) leaves the company and shows the platform's own look
        foreach (['/platform', '/platform/services', '/platform/administrators', '/platform/branding'] as $path) {
            $this->withSession(['tenant_id' => $acme->id])->get($path)->assertOk()->assertInertia(fn ($p) => $p
                ->where('app.name', config('app.name'))->where('app.color', '#4f46e5')->where('app.tagline', 'Build. Connect. Automate.')->where('app.timezone', config('app.timezone')));
            $this->assertNull(session('tenant_id'), "{$path} must close the open company");
            $this->assertFalse(tenancy()->initialized);
        }
    }

    public function test_a_signed_in_platform_administrator_is_sent_back_from_the_sign_in_and_sign_up_pages(): void
    {
        $this->platformAdmin();
        $this->get('/login')->assertOk();                                                                    // signed out: the page shows

        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1'])->assertRedirect('/platform');

        $this->get('/login')->assertRedirect('/platform');
        $this->get('/signup')->assertRedirect('/platform');
        $this->get('/forgot-password')->assertOk();                                                          // a reset link keeps working

        $this->post('/logout');
        $this->get('/login')->assertOk();
    }

    public function test_a_platform_administrator_changes_the_address_of_a_company_without_touching_its_data(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $this->platformAdmin();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
        $databaseBefore = $acme->fresh()->database()->getName();
        $url = "/platform/companies/{$acme->getTenantKey()}";

        $this->patch($url, ['slug' => 'acme-bakery'])->assertSessionHasNoErrors();
        $this->assertSame('acme-bakery', $acme->fresh()->slug);
        $this->assertSame($databaseBefore, $acme->fresh()->database()->getName());

        $this->patch($url, ['slug' => $beta->slug])->assertSessionHasErrors('slug');                 // taken
        $this->patch($url, ['slug' => 'admin'])->assertSessionHasErrors('slug');                     // reserved
        $this->patch($url, ['slug' => 'Not Valid!'])->assertSessionHasErrors('slug');
        $this->patch($url, ['slug' => 'acme-bakery', 'name' => 'Acme Bakery'])->assertSessionHasNoErrors();   // its own address is not "taken"
        $this->assertSame('acme-bakery', $acme->fresh()->slug);
    }
}
