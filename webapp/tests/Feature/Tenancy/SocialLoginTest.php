<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\SocialIdentity;
use App\Services\Auth\SocialProviders;
use App\Services\Auth\TwoFactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** "Continue with Google / Microsoft / GitHub / single sign-on": the flow, what it refuses, and connecting and disconnecting accounts. */
class SocialLoginTest extends TenantTestCase
{
    private const PASSWORD = 'Owner-Passw0rd-1';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        PlatformSetting::forgetCache();
        $this->provider('google');
    }

    private function provider(string $key, array $extra = []): void
    {
        app(SocialProviders::class)->save($key, ['enabled' => true, 'client_id' => "{$key}-client", 'client_secret' => "{$key}-secret"] + $extra);
        PlatformSetting::forgetCache();
    }

    /** Starts the flow and returns the state and nonce the app put in the address (what the provider would hand back). */
    private function begin(string $key = 'google'): array
    {
        $location = $this->get("/auth/{$key}/redirect")->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        return ['state' => $q['state'], 'nonce' => $q['nonce'] ?? null, 'query' => $q, 'location' => $location];
    }

    private function jwt(array $claims): string
    {
        $b64 = fn (array $a) => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');

        return $b64(['alg' => 'RS256']).'.'.$b64($claims).'.sig';
    }

    /** Fakes Google saying "this is $sub, $email" for the flow that was just started. */
    private function google(array $flow, string $sub, ?string $email, bool $verified = true, array $claims = []): void
    {
        $this->fakeHttp([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'id_token' => $this->jwt($claims + ['iss' => 'https://accounts.google.com', 'aud' => 'google-client', 'exp' => time() + 600, 'nonce' => $flow['nonce'], 'sub' => $sub])]),
            'openidconnect.googleapis.com/*' => Http::response(['sub' => $sub, 'email' => $email, 'email_verified' => $verified, 'name' => 'Some One']),
        ]);
    }

    public function test_the_sign_in_page_offers_only_the_providers_that_are_on_and_complete(): void
    {
        app(SocialProviders::class)->save('github', ['enabled' => true, 'client_id' => 'gh']);               // no secret: incomplete
        app(SocialProviders::class)->save('microsoft', ['enabled' => false, 'client_id' => 'ms', 'client_secret' => 's', 'tenant' => 'contoso.onmicrosoft.com']);
        PlatformSetting::forgetCache();

        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Login')->has('providers', 1)->where('providers.0.key', 'google')->where('providers.0.name', 'Google'));
        $this->get('/auth/github/redirect')->assertNotFound();
        $this->get('/auth/microsoft/redirect')->assertNotFound();
        $this->get('/auth/nonsense/redirect')->assertNotFound();
    }

    public function test_the_redirect_carries_pkce_state_nonce_and_the_right_scopes(): void
    {
        $flow = $this->begin();

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $flow['location']);
        $this->assertSame('google-client', $flow['query']['client_id']);
        $this->assertSame(url('/auth/google/callback'), $flow['query']['redirect_uri']);
        $this->assertSame('code', $flow['query']['response_type']);
        $this->assertSame('openid email profile', $flow['query']['scope']);
        $this->assertSame('S256', $flow['query']['code_challenge_method']);
        $this->assertGreaterThan(40, strlen($flow['query']['code_challenge']));
        $this->assertGreaterThan(30, strlen($flow['state']));
        $this->assertNotEmpty($flow['nonce']);
    }

    public function test_an_existing_account_signs_in_by_its_verified_email_and_is_remembered_by_the_providers_id(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $flow = $this->begin();
        $this->google($flow, 'g-123', 'Owner@Acme.test');

        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertSame($acme->id, session('tenant_id'));
        $linked = SocialIdentity::where('provider', 'google')->firstOrFail();
        $this->assertSame('g-123', $linked->provider_user_id);
        $this->assertSame($this->identity('owner@acme.test')->id, $linked->account_user_id);

        // the code is exchanged with the secret and the PKCE verifier, server to server
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['client_secret'] === 'google-secret' && strlen($r['code_verifier']) > 40 && $r['grant_type'] === 'authorization_code');

        // later the provider's id is what counts: even a changed address on their side still signs the same person in
        $this->post('/logout');
        $flow = $this->begin();
        $this->google($flow, 'g-123', 'new-address@elsewhere.test');
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_it_refuses_unknown_people_unverified_addresses_and_inactive_accounts(): void
    {
        $this->company('Acme', 'owner@acme.test');

        $flow = $this->begin();
        $this->google($flow, 'g-1', 'stranger@x.test');
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, SocialIdentity::count());

        $flow = $this->begin();
        $this->google($flow, 'g-2', 'owner@acme.test', verified: false);          // an address nobody proved is not a way in to that account
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, SocialIdentity::count());

        $this->identity('owner@acme.test')->update(['status' => 'suspended']);
        $flow = $this->begin();
        $this->google($flow, 'g-3', 'owner@acme.test');
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_forged_or_stale_callback_does_nothing(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $flow = $this->begin();
        $this->google($flow, 'g-1', 'owner@acme.test');

        $this->get('/auth/google/callback?code=abc&state=forged')->assertSessionHasErrors('email');            // wrong state
        $this->assertGuest();
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');   // and the pending login was used up
        $this->assertGuest();

        $flow = $this->begin();
        $this->google($flow, 'g-1', 'owner@acme.test', claims: ['nonce' => 'someone-elses']);                  // a token made for another login
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');
        $flow = $this->begin();
        $this->google($flow, 'g-1', 'owner@acme.test', claims: ['aud' => 'another-app']);                      // a token made for another application
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');
        $flow = $this->begin();
        $this->google($flow, 'g-1', 'owner@acme.test', claims: ['exp' => time() - 10]);
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');
        $flow = $this->begin();
        $this->google($flow, 'g-1', 'owner@acme.test', claims: ['iss' => 'https://evil.test']);
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $flow = $this->begin();
        $this->get('/auth/google/callback?error=access_denied&state='.$flow['state'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_somebody_with_two_step_verification_still_gives_the_code_after_the_provider(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $secret = app(TwoFactor::class)->newSecret();
        app(TwoFactor::class)->confirm($this->identity('owner@acme.test'), $secret, app(TwoFactor::class)->code($secret, intdiv(time(), 30)));

        $flow = $this->begin();
        $this->google($flow, 'g-1', 'owner@acme.test');
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
    }

    public function test_microsoft_only_vouches_for_addresses_when_limited_to_one_directory(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->provider('microsoft', ['tenant' => 'common']);
        $flow = $this->begin('microsoft');
        $this->fakeHttp([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'at', 'id_token' => $this->jwt(['iss' => 'https://login.microsoftonline.com/common/v2.0', 'aud' => 'microsoft-client', 'exp' => time() + 600, 'nonce' => $flow['nonce'], 'sub' => 'm-1', 'tid' => 't'])]),
            'graph.microsoft.com/*' => Http::response(['sub' => 'm-1', 'email' => 'owner@acme.test']),
        ]);
        $this->get('/auth/microsoft/callback?code=abc&state='.$flow['state'])->assertSessionHasErrors('email');   // "common" lets anybody in: not proof of an address
        $this->assertGuest();

        $this->provider('microsoft', ['tenant' => 'contoso.onmicrosoft.com']);
        $flow = $this->begin('microsoft');
        $this->assertStringContainsString('/contoso.onmicrosoft.com/oauth2/v2.0/authorize', $flow['location']);
        $this->fakeHttp([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'at', 'id_token' => $this->jwt(['iss' => 'https://login.microsoftonline.com/contoso.onmicrosoft.com/v2.0', 'aud' => 'microsoft-client', 'exp' => time() + 600, 'nonce' => $flow['nonce'], 'sub' => 'm-1', 'tid' => 't'])]),
            'graph.microsoft.com/*' => Http::response(['sub' => 'm-1', 'email' => 'owner@acme.test']),
        ]);
        $this->get('/auth/microsoft/callback?code=abc&state='.$flow['state'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_github_uses_the_primary_verified_address(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->provider('github');
        $flow = $this->begin('github');
        $this->assertArrayNotHasKey('nonce', $flow['query']);                       // GitHub is plain OAuth, not OpenID Connect
        $this->fakeHttp([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'at']),
            'api.github.com/user/emails' => Http::response([['email' => 'old@x.test', 'primary' => false, 'verified' => true], ['email' => 'owner@acme.test', 'primary' => true, 'verified' => true]]),
            'api.github.com/user' => Http::response(['id' => 4242, 'login' => 'octo', 'name' => 'Octo']),
        ]);
        $this->get('/auth/github/callback?code=abc&state='.$flow['state'])->assertRedirect('/');
        $this->assertSame('4242', SocialIdentity::where('provider', 'github')->value('provider_user_id'));
    }

    public function test_a_custom_openid_connect_provider_is_discovered_from_its_address(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->fakeHttp(['sso.acme.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://sso.acme.test', 'authorization_endpoint' => 'https://sso.acme.test/authorize', 'token_endpoint' => 'https://sso.acme.test/token', 'userinfo_endpoint' => 'https://sso.acme.test/userinfo',
        ])]);
        $this->provider('oidc', ['issuer' => 'https://sso.acme.test/', 'label' => 'Acme SSO']);
        $this->get('/login')->assertInertia(fn ($p) => $p->where('providers.1.name', 'Acme SSO'));

        $flow = $this->begin('oidc');
        $this->assertStringStartsWith('https://sso.acme.test/authorize?', $flow['location']);
        $this->fakeHttp([
            'sso.acme.test/token' => Http::response(['access_token' => 'at', 'id_token' => $this->jwt(['iss' => 'https://sso.acme.test', 'aud' => 'oidc-client', 'exp' => time() + 600, 'nonce' => $flow['nonce'], 'sub' => 'u-9'])]),
            'sso.acme.test/userinfo' => Http::response(['sub' => 'u-9', 'email' => 'owner@acme.test', 'email_verified' => true]),
        ]);
        $this->get('/auth/oidc/callback?code=abc&state='.$flow['state'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_a_signed_in_person_connects_and_disconnects_a_provider_and_cannot_take_over_anothers(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $other = $this->company('Beta', 'owner@beta.test');
        SocialIdentity::create(['account_user_id' => $this->identity('owner@beta.test')->id, 'provider' => 'google', 'provider_user_id' => 'taken']);

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $location = $this->get('/account/security/social/google/link')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $flow = ['state' => $q['state'], 'nonce' => $q['nonce']];

        $this->google($flow, 'taken', 'whatever@x.test');
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertRedirect('/security')->assertSessionHas('warning');
        $this->assertSame(0, SocialIdentity::where('account_user_id', $this->identity('owner@acme.test')->id)->count());

        $location = $this->get('/account/security/social/google/link')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $flow = ['state' => $q['state'], 'nonce' => $q['nonce']];
        $this->google($flow, 'mine', 'my-own@gmail.test');
        $this->get('/auth/google/callback?code=abc&state='.$flow['state'])->assertRedirect('/security')->assertSessionHas('success');
        $this->assertSame('mine', SocialIdentity::where('account_user_id', $this->identity('owner@acme.test')->id)->value('provider_user_id'));

        $this->post('/account/security/social/google/disconnect', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(1, SocialIdentity::where('account_user_id', $this->identity('owner@acme.test')->id)->count());
        $this->post('/account/security/social/google/disconnect', ['password' => self::PASSWORD])->assertSessionHas('success');
        $this->assertSame(0, SocialIdentity::where('account_user_id', $this->identity('owner@acme.test')->id)->count());
        $this->assertNotNull($other);
    }

    public function test_only_platform_administrators_set_providers_and_the_secret_is_kept_hidden(): void
    {
        AccountUser::create(['name' => 'Platform', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->patch('/platform/settings/security/social/github', ['enabled' => true, 'client_id' => ''])->assertSessionHasErrors('client_id');
        $this->patch('/platform/settings/security/social/microsoft', ['enabled' => true, 'client_id' => 'ms'])->assertSessionHasErrors('tenant');
        $this->patch('/platform/settings/security/social/oidc', ['enabled' => true, 'client_id' => 'x', 'issuer' => 'http://insecure.test'])->assertSessionHasErrors('issuer');
        $this->patch('/platform/settings/security/social/nonsense', ['enabled' => false])->assertNotFound();

        $this->patch('/platform/settings/security/social/github', ['enabled' => true, 'client_id' => 'gh-id', 'client_secret' => 'gh-secret'])->assertSessionHas('success');
        $page = $this->get('/platform/settings/security')->viewData('page')['props'];
        $github = collect($page['social'])->firstWhere('key', 'github');
        $this->assertTrue($github['active']);
        $this->assertTrue($github['secret_set']);
        $this->assertStringNotContainsString('gh-secret', json_encode($page));
        $this->assertStringNotContainsString('gh-secret', (string) PlatformSetting::get('social.github.client_secret'), 'stored encrypted');
        $this->assertSame(url('/auth/github/callback'), $github['callback']);

        $this->patch('/platform/settings/security/social/github', ['enabled' => true, 'client_id' => 'gh-id'])->assertSessionHas('success');
        $this->assertTrue(app(SocialProviders::class)->active('github'), 'saving without a secret keeps the saved one');
        $this->patch('/platform/settings/security/social/github', ['enabled' => true, 'client_id' => 'gh-id', 'clear_secret' => true]);
        $this->assertFalse(app(SocialProviders::class)->active('github'));

        $this->company('Acme', 'owner@acme.test');
        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->patch('/platform/settings/security/social/github', ['enabled' => false])->assertForbidden();
    }
}
