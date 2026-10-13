<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\EvaluateAutomation;
use App\Jobs\TenantTick;
use App\Models\AccountUser;
use App\Models\Automation;
use App\Models\Membership;
use App\Models\User;
use App\Services\Tenancy\CompanyScheduler;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Bus;
use Tests\TenantTestCase;

/** Platform control of background services, and keeping platform administrators out of company user management. */
class ServicesAndAdministratorsTest extends TenantTestCase
{
    private function platformAdmin(string $email = 'platform@x.test'): AccountUser
    {
        return AccountUser::create(['name' => 'Platform Admin', 'email' => $email, 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
    }

    private function signInPlatform(string $email = 'platform@x.test'): void
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => $email, 'password' => 'Platform-Passw0rd-1']);
    }

    private function asCompanyUser(string $email, string $company, string $password = 'Owner-Passw0rd-1'): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $password, 'company' => $company])->assertOk()->json('token');

        return $this->withToken($token);
    }

    // ---- background services ------------------------------------------------------------------------------------

    public function test_the_platform_lists_every_service_of_every_company(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $this->platformAdmin();
        $this->signInPlatform();

        $page = $this->get('/platform/services')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Services')->has('companies', 2)->has('catalog', count(CompanyServices::CATALOG)));
        $this->assertTrue($page->viewData('page')['props']['companies'][0]['services']['automations']['enabled']);
    }

    public function test_services_pages_are_for_platform_administrators_only(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->get('/platform/services')->assertForbidden();
        $this->patch('/platform/services', ['company' => $acme->id, 'service' => 'automations', 'enabled' => false])->assertForbidden();
        $this->post("/platform/companies/{$acme->id}/services/run")->assertForbidden();
        $this->assertTrue(app(CompanyServices::class)->enabled('automations', $acme));
    }

    public function test_switching_a_service_off_stops_that_company_only_and_back_on_resumes_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        foreach ([$acme, $beta] as $company) {
            $company->run(fn () => Automation::create(['name' => 'Every minute', 'schedule' => 'every_minute', 'status' => 'active', 'data_object_id' => null, 'conditions' => [], 'actions' => []]));
        }
        $this->platformAdmin();
        $this->signInPlatform();

        $this->patch('/platform/services', ['company' => $acme->id, 'service' => 'automations', 'enabled' => false])->assertRedirect();

        Bus::fake([EvaluateAutomation::class]);
        $now = now()->startOfMinute();
        $ranAcme = $acme->run(fn () => app(CompanyScheduler::class)->run($now));
        $ranBeta = $beta->run(fn () => app(CompanyScheduler::class)->run($now));
        $this->assertSame([], preg_grep('/^automation:/', $ranAcme), 'a switched-off service dispatches nothing');
        $this->assertNotEmpty(preg_grep('/^automation:/', $ranBeta), "another company's services are unaffected");

        $this->patch('/platform/services', ['company' => $acme->id, 'service' => 'automations', 'enabled' => true]);
        sleep(6);                                                           // switches are re-read after a few seconds in a running process
        $this->assertNotEmpty(preg_grep('/^automation:/', $acme->run(fn () => app(CompanyScheduler::class)->run($now))));
        $this->assertNotNull(app(CompanyServices::class)->states($beta)['automations']['last_run_at']);
    }

    public function test_the_job_itself_also_stands_down_when_its_service_is_off(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        app(CompanyServices::class)->set($acme, 'automations', false);

        $acme->run(function () {
            $automation = Automation::create(['name' => 'A', 'schedule' => 'every_minute', 'status' => 'active', 'conditions' => [], 'actions' => []]);
            app()->call([new EvaluateAutomation($automation->id), 'handle']);
            $this->assertSame(0, \DB::table('automation_runs')->count());
        });
    }

    public function test_pause_and_resume_all_for_all_companies_and_run_now(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $this->platformAdmin();
        $this->signInPlatform();

        $this->patch('/platform/services', ['company' => '*', 'service' => '*', 'enabled' => false])->assertRedirect();
        foreach ([$acme, $beta] as $company) {
            foreach (CompanyServices::CATALOG as $key => $meta) {
                $this->assertFalse((new CompanyServices)->enabled($key, $company), "{$key} should be off");
            }
        }

        $this->patch('/platform/services', ['company' => '*', 'service' => 'messages', 'enabled' => true]);
        $this->assertTrue((new CompanyServices)->enabled('messages', $beta));
        $this->assertFalse((new CompanyServices)->enabled('automations', $beta));

        $this->patch('/platform/services', ['company' => $acme->id, 'service' => 'bogus', 'enabled' => true])->assertSessionHasErrors('service');
        $this->patch('/platform/services', ['company' => 'nope', 'service' => 'messages', 'enabled' => true])->assertNotFound();

        Bus::fake([TenantTick::class]);
        $this->post("/platform/companies/{$acme->id}/services/run")->assertRedirect();
        Bus::assertDispatched(TenantTick::class, fn ($job) => $job->tenantId === $acme->id);
    }

    // ---- who appears in a company's user list ---------------------------------------------------------------------

    public function test_platform_administrators_never_appear_in_a_companys_user_list(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $platform = $this->platformAdmin();
        $this->signInPlatform();
        $this->post("/platform/companies/{$acme->id}/open")->assertRedirect();       // support access creates a profile in Acme
        $supportProfile = $acme->run(fn () => User::where('central_user_id', $platform->id)->firstOrFail());
        $this->assertTrue($supportProfile->is_platform_support);

        $list = $this->asCompanyUser('owner@acme.test', 'acme')->getJson('/api/v1/users')->assertOk()->json('data');
        $this->assertSame(['owner@acme.test'], array_column($list, 'email'), 'only the company\'s own people');
        $this->assertNotContains($supportProfile->id, array_column($list, 'id'));
    }

    public function test_a_company_administrator_cannot_touch_a_platform_administrator(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $platform = $this->platformAdmin();
        $profile = app(TenantProvisioner::class)->addSupportProfile($acme, $platform);
        $client = $this->asCompanyUser('owner@acme.test', 'acme');

        $client->patchJson("/api/v1/users/{$profile->id}", ['role' => 'user'])->assertNotFound();
        $client->postJson("/api/v1/users/{$profile->id}/reset-password")->assertNotFound();
        $client->deleteJson("/api/v1/users/{$profile->id}")->assertNotFound();
        $client->postJson('/api/v1/users', ['name' => 'Sneaky', 'email' => 'platform@x.test', 'role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue($platform->fresh()->is_platform_admin);
        $acme->run(fn () => $this->assertTrue(User::whereKey($profile->id)->exists()));
        $this->assertFalse(Membership::where('account_user_id', $platform->id)->exists());
    }

    public function test_a_company_administrator_only_sees_their_own_company_and_a_regular_user_no_admin_settings(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $this->asCompanyUser('owner@acme.test', 'acme')->postJson('/api/v1/users', ['name' => 'Rae Regular', 'email' => 'rae@acme.test', 'password' => 'Regular-Passw0rd-1', 'role' => 'user'])->assertCreated();

        $emails = array_column($this->asCompanyUser('owner@acme.test', 'acme')->getJson('/api/v1/users')->json('data'), 'email');
        sort($emails);
        $this->assertSame(['owner@acme.test', 'rae@acme.test'], $emails);

        $rae = $this->asCompanyUser('rae@acme.test', 'acme', 'Regular-Passw0rd-1');
        $light = $rae->getJson('/api/v1/users')->assertOk()->json('data');
        $this->assertSame(['id', 'name'], array_keys($light[0]), 'a regular user only gets names for pickers');
        $rae->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@acme.test', 'role' => 'user'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'rae@acme.test', 'password' => 'Regular-Passw0rd-1'])->assertRedirect('/');
        foreach (['/admin', '/admin/users', '/admin/app-details', '/admin/forms', '/automations'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->where('auth.user.is_admin', false));
    }

    // ---- managing platform administrators -------------------------------------------------------------------------

    public function test_promoting_an_existing_person_hides_them_from_their_company_and_demoting_can_keep_them_as_admin(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->asCompanyUser('owner@acme.test', 'acme')->postJson('/api/v1/users', ['name' => 'Pat Power', 'email' => 'pat@acme.test', 'password' => 'Pat-Passw0rd-12', 'role' => 'admin'])->assertCreated();
        $this->platformAdmin();
        $this->signInPlatform();

        $this->post('/platform/administrators', ['name' => 'Pat Power', 'email' => 'pat@acme.test'])->assertRedirect()->assertSessionHas('success');
        $pat = $this->identity('pat@acme.test');
        $this->assertTrue($pat->is_platform_admin);
        $this->assertFalse(Membership::where('account_user_id', $pat->id)->exists());
        $list = $this->asCompanyUser('owner@acme.test', 'acme')->getJson('/api/v1/users')->json('data');
        $this->assertSame(['owner@acme.test'], array_column($list, 'email'));

        $this->signInPlatform();
        $this->delete("/platform/administrators/{$pat->id}", ['company' => $acme->id])->assertRedirect();
        $this->assertFalse($pat->fresh()->is_platform_admin);
        $this->assertTrue(Membership::where('account_user_id', $pat->id)->where('tenant_id', $acme->id)->exists());
        $emails = array_column($this->asCompanyUser('owner@acme.test', 'acme')->getJson('/api/v1/users')->json('data'), 'email');
        $this->assertContains('pat@acme.test', $emails);
    }

    public function test_promotion_is_refused_when_it_would_leave_a_company_without_an_administrator(): void
    {
        $this->company('Solo', 'solo@solo.test');
        $this->platformAdmin();
        $this->signInPlatform();

        $this->post('/platform/administrators', ['name' => 'Solo Owner', 'email' => 'solo@solo.test'])->assertSessionHasErrors('email');
        $this->assertFalse($this->identity('solo@solo.test')->is_platform_admin);
    }

    public function test_adding_editing_and_demoting_platform_administrators_has_guards(): void
    {
        $me = $this->platformAdmin();
        $this->signInPlatform();

        $this->post('/platform/administrators', ['name' => 'Second Admin', 'email' => 'second@x.test', 'password' => 'Second-Passw0rd-1'])->assertRedirect();
        $second = $this->identity('second@x.test');
        $this->assertTrue($second->is_platform_admin);
        $this->post('/platform/administrators', ['name' => 'Second Admin', 'email' => 'second@x.test'])->assertSessionHasErrors('email');   // already one

        $this->patch("/platform/administrators/{$second->id}", ['name' => 'Renamed'])->assertRedirect();
        $this->assertSame('Renamed', $second->fresh()->name);
        $this->patch("/platform/administrators/{$second->id}", ['email' => 'platform@x.test'])->assertSessionHasErrors('email');

        $this->delete("/platform/administrators/{$me->id}")->assertUnprocessable();             // not yourself
        $this->patch("/platform/administrators/{$me->id}", ['status' => 'suspended'])->assertUnprocessable();
        $this->assertTrue($me->fresh()->is_platform_admin);

        $this->delete("/platform/administrators/{$second->id}")->assertRedirect();
        $this->assertFalse($second->fresh()->is_platform_admin);

        $ordinary = AccountUser::create(['name' => 'Ordinary', 'email' => 'o@x.test', 'password' => 'Ordinary-Passw0rd-1']);
        $this->patch("/platform/administrators/{$ordinary->id}", ['name' => 'Hijack'])->assertNotFound();      // only platform administrators are managed here
    }

    public function test_demoting_removes_support_profiles_from_every_company(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $me = $this->platformAdmin();
        $other = $this->platformAdmin('other@x.test');
        app(TenantProvisioner::class)->addSupportProfile($acme, $other);
        $this->signInPlatform();

        $this->delete("/platform/administrators/{$other->id}")->assertRedirect();
        $acme->run(fn () => $this->assertFalse(User::where('central_user_id', $other->id)->exists()));
        $this->assertTrue($me->fresh()->is_platform_admin);
    }
}
