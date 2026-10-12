<?php

namespace Tests\Feature\Tenancy;

use App\Models\Contact;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Password;
use Tests\TenantTestCase;

class ApiAccessTest extends TenantTestCase
{
    /** Request as the holder of $token (forgetting the guard the previous request cached, as a new HTTP call would). */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function token(string $email, string $password = 'Owner-Passw0rd-1', ?string $company = null): string
    {
        return $this->postJson('/api/v1/auth/token', array_filter(['email' => $email, 'password' => $password, 'company' => $company]))->assertOk()->json('token');
    }

    public function test_a_token_only_ever_sees_its_own_company(): void
    {
        $this->company('Alpha', 'a@alpha.test');
        $this->company('Beta', 'b@beta.test');
        $alpha = $this->token('a@alpha.test');
        $beta = $this->token('b@beta.test');

        $this->as($alpha)->postJson('/api/v1/contacts', ['first_name' => 'Ann', 'last_name' => 'Alpha', 'email' => 'ann@alpha.test'])->assertCreated();
        $this->as($beta)->postJson('/api/v1/contacts', ['first_name' => 'Bob', 'last_name' => 'Beta', 'email' => 'bob@beta.test'])->assertCreated();

        $this->as($alpha)->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Alpha');
        $this->as($beta)->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Beta');
        $this->as($beta)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'b@beta.test');

        // the same email address may exist in both companies: they are different people there
        $this->as($alpha)->postJson('/api/v1/contacts', ['first_name' => 'Dup', 'email' => 'bob@beta.test'])->assertCreated();
    }

    public function test_someone_in_several_companies_must_say_which_one_the_token_is_for(): void
    {
        $this->company('Alpha', 'multi@x.test');
        $beta = $this->company('Beta', 'b@beta.test');
        app(TenantProvisioner::class)->addMember($beta, $this->identity('multi@x.test'), 'user');

        $this->postJson('/api/v1/auth/token', ['email' => 'multi@x.test', 'password' => 'Owner-Passw0rd-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('company');
        $this->postJson('/api/v1/auth/token', ['email' => 'multi@x.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'nope'])->assertUnprocessable();

        $forBeta = $this->token('multi@x.test', company: 'beta');
        $this->as($forBeta)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'user');           // the profile in THAT company
        $forAlpha = $this->token('multi@x.test', company: 'alpha');
        $this->as($forAlpha)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_a_suspended_company_or_person_loses_access_at_once(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $token = $this->token('owner@acme.test');
        $this->as($token)->getJson('/api/v1/me')->assertOk();

        $acme->update(['status' => 'suspended']);
        $this->as($token)->getJson('/api/v1/me')->assertForbidden();

        $acme->update(['status' => 'active']);
        $this->identity('owner@acme.test')->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/token', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertUnprocessable();
    }

    public function test_a_password_reset_revokes_the_persons_tokens_in_every_company(): void
    {
        $this->company('Alpha', 'multi@x.test');
        $beta = $this->company('Beta', 'b@beta.test');
        app(TenantProvisioner::class)->addMember($beta, $this->identity('multi@x.test'), 'user');
        $inAlpha = $this->token('multi@x.test', company: 'alpha');
        $inBeta = $this->token('multi@x.test', company: 'beta');
        $other = $this->token('b@beta.test');

        $resetToken = Password::createToken($this->identity('multi@x.test'));
        $this->post('/reset-password', ['token' => $resetToken, 'email' => 'multi@x.test', 'password' => 'Brand-New-Passw0rd-9', 'password_confirmation' => 'Brand-New-Passw0rd-9'])
            ->assertRedirect('/login');

        $this->as($inAlpha)->getJson('/api/v1/me')->assertUnauthorized();
        $this->as($inBeta)->getJson('/api/v1/me')->assertUnauthorized();
        $this->as($other)->getJson('/api/v1/me')->assertOk();                         // somebody else's token is untouched
        $this->postJson('/api/v1/auth/token', ['email' => 'multi@x.test', 'password' => 'Brand-New-Passw0rd-9', 'company' => 'alpha'])->assertOk();
    }

    public function test_a_browser_session_reaches_the_api_in_its_own_company(): void
    {
        // The SPA calls /api/v1 with only its session cookie. Sanctum looks the person up while the session starts, so the
        // company has to be open by then (AuthenticateSessionInCompany): this used to 500 with "no such table: users"
        // because the lookup ran against the central database.
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $a->run(fn () => Contact::create(['first_name' => 'Ann', 'last_name' => 'Alpha', 'email' => 'ann@alpha.test']));
        $profileId = fn ($company) => $company->run(fn () => User::firstOrFail()->id);
        $loginKey = auth()->guard('web')->getName();

        $session = fn ($company) => ['tenant_id' => $company->id, $loginKey => $profileId($company)];
        $stateful = ['Referer' => config('app.url'), 'Origin' => config('app.url')];

        $this->app['auth']->forgetGuards();
        $this->withHeaders($stateful)->withSession($session($a))->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(1, 'data');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($stateful)->withSession($session($b))->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->flushSession();                 // withSession() merges into the existing test session
        $this->withHeaders($stateful)->withSession(['tenant_id' => $a->id])->getJson('/api/v1/contacts')->assertUnauthorized();      // company chosen, nobody signed in
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_tokens_are_scoped_to_the_company_even_when_profile_ids_collide(): void
    {
        // both owners are profile #1 in their own database
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $this->token('a@alpha.test');
        $this->token('b@beta.test');

        $this->assertSame(2, PersonalAccessToken::withoutGlobalScopes()->count());
        $a->run(fn () => $this->assertSame(1, PersonalAccessToken::count(), 'inside a company only its own tokens are visible'));
        $b->run(fn () => $this->assertSame(1, PersonalAccessToken::count()));
        $this->assertSame(0, Contact::count() ?? 0);
    }
}
