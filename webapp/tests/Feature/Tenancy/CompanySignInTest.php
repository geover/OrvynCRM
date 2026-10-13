<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\User;
use App\Services\Tenancy\CompanySession;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\LoginThrottle;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TenantTestCase;

class CompanySignInTest extends TenantTestCase
{
    public function test_a_person_in_one_company_goes_straight_in(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');

        $this->post('/login', ['email' => 'Owner@Acme.test', 'password' => 'Owner-Passw0rd-1'])->assertRedirect('/');

        $this->assertSame($acme->id, session('tenant_id'));
        $this->assertSame($this->identity('owner@acme.test')->id, session('identity_id'));
        $this->assertAuthenticated();
    }

    public function test_wrong_passwords_unknown_emails_suspended_people_and_people_without_a_company_are_refused(): void
    {
        $this->company('Acme', 'owner@acme.test');
        AccountUser::create(['name' => 'Nobody', 'email' => 'nobody@x.test', 'password' => 'Nobody-Passw0rd-1']);          // no membership
        $this->identity('owner@acme.test')->update(['status' => 'suspended']);

        foreach ([['owner@acme.test', 'Owner-Passw0rd-1'], ['nobody@x.test', 'Nobody-Passw0rd-1'], ['ghost@x.test', 'whatever-1234'], ['nobody@x.test', 'wrong']] as [$email, $password]) {
            $this->post('/login', ['email' => $email, 'password' => $password])->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertNull(session('tenant_id'));
        }
    }

    public function test_someone_in_several_companies_chooses_one_and_cannot_choose_a_stranger(): void
    {
        $acme = $this->company('Acme', 'consultant@x.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $stranger = $this->company('Gamma', 'owner@gamma.test');
        app(TenantProvisioner::class)->addMember($beta, $this->identity('consultant@x.test'), 'user');

        $this->post('/login', ['email' => 'consultant@x.test', 'password' => 'Owner-Passw0rd-1'])->assertRedirect('/choose-company');
        $this->assertNull(session('tenant_id'));
        $this->get('/choose-company')->assertOk()->assertSee('Acme')->assertSee('Beta')->assertDontSee('Gamma');

        $this->post('/choose-company', ['company' => $stranger->id])->assertSessionHasErrors('company');
        $this->assertNull(session('tenant_id'));

        $this->post('/choose-company', ['company' => $beta->id])->assertRedirect('/');
        $this->assertSame($beta->id, session('tenant_id'));
        $this->assertSame('user', auth()->guard('web')->user()->role, 'the profile in THIS company, not the one in Acme');
    }

    public function test_a_company_that_is_suspended_cannot_be_entered_and_other_companies_still_can(): void
    {
        $acme = $this->company('Acme', 'multi@x.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        app(TenantProvisioner::class)->addMember($beta, $this->identity('multi@x.test'), 'user');
        $acme->update(['status' => 'suspended']);

        // only Beta is left, so the person goes straight into it
        $this->post('/login', ['email' => 'multi@x.test', 'password' => 'Owner-Passw0rd-1'])->assertRedirect('/');
        $this->assertSame($beta->id, session('tenant_id'));
    }

    public function test_only_failed_attempts_count_and_a_success_resets_the_counter(): void
    {
        $this->company('Acme', 'throttle@x.test', 'Right-Passw0rd-1');

        // many SUCCESSFUL sign-ins in a row are never blocked
        foreach (range(1, 6) as $i) {
            $this->app['auth']->forgetGuards();
            $this->post('/login', ['email' => 'throttle@x.test', 'password' => 'Right-Passw0rd-1'])->assertRedirect('/');
        }

        // 4 wrong passwords, then a success resets the counter...
        $this->app['auth']->forgetGuards();
        $this->flushSession();                    // signed out again: the guest-only login route would bounce a signed-in session
        foreach (range(1, 4) as $i) {
            $this->post('/login', ['email' => 'throttle@x.test', 'password' => 'nope'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'throttle@x.test', 'password' => 'Right-Passw0rd-1'])->assertRedirect('/');

        // ...so 5 fresh wrong passwords are needed to lock; after that even the RIGHT password is refused
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => 'throttle@x.test', 'password' => 'nope'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'throttle@x.test', 'password' => 'Right-Passw0rd-1'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many failed sign-in attempts', session('errors')->first('email'));

        // the API token endpoint shares the same protection, other addresses are unaffected
        $this->postJson('/api/v1/auth/token', ['email' => 'throttle@x.test', 'password' => 'Right-Passw0rd-1'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/token', ['email' => 'someone.else@x.test', 'password' => 'x'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        RateLimiter::clear(LoginThrottle::key(request()->merge(['email' => 'throttle@x.test'])));
        $this->postJson('/api/v1/auth/token', ['email' => 'throttle@x.test', 'password' => 'Right-Passw0rd-1'])->assertOk()->assertJsonStructure(['token', 'company']);
    }

    public function test_someone_suspended_in_their_only_company_is_told_so_on_the_sign_in_page(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->run(fn () => User::where('email', 'owner@acme.test')->update(['status' => 'suspended']));

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertSessionHasErrors('email');   // the field the page shows

        $this->assertStringContainsString('suspended', session('errors')->first('email'));
        $this->assertNull(session('tenant_id'));
        $this->assertNull(session('identity_id'));
        $this->assertGuest();
    }

    public function test_the_chooser_offers_the_company_used_last_first_and_the_rest_alphabetically(): void
    {
        $this->company('Acme', 'multi@x.test');
        $beta = $this->company('Beta', 'b@beta.test');
        $zulu = $this->company('Zulu', 'z@zulu.test');
        foreach ([$beta, $zulu] as $c) {
            app(TenantProvisioner::class)->addMember($c, $this->identity('multi@x.test'), 'user');
        }

        $this->post('/login', ['email' => 'multi@x.test', 'password' => 'Owner-Passw0rd-1'])->assertRedirect('/choose-company');
        $this->get('/choose-company')->assertInertia(fn ($page) => $page->where('companies.0.slug', 'acme')->where('companies.2.slug', 'zulu')->where('last', null));

        // after working in Zulu, Zulu is offered first next time
        $this->withCookie(CompanySession::LAST_COOKIE, $zulu->id)->get('/choose-company')
            ->assertInertia(fn ($page) => $page->where('companies.0.slug', 'zulu')->where('last', $zulu->id)->where('companies.1.slug', 'acme')->where('companies.2.slug', 'beta'));

        // a stale hint (a company they no longer belong to) is ignored
        $this->withCookie(CompanySession::LAST_COOKIE, 'nosuchcompany')->get('/choose-company')
            ->assertInertia(fn ($page) => $page->where('companies.0.slug', 'acme')->where('last', null));
    }
}
