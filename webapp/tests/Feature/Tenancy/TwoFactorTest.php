<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Auth\SignInSecurity;
use App\Services\Auth\TwoFactor;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** Two-step verification: the codes themselves, setting it up, the second step of signing in, recovery codes, the platform's policy and API tokens. */
class TwoFactorTest extends TenantTestCase
{
    private const PASSWORD = 'Owner-Passw0rd-1';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function twoFactor(): TwoFactor
    {
        return app(TwoFactor::class);
    }

    /** Turns it on for the person (the way the page does) and returns [secret, recovery codes]. */
    private function enrol(string $email): array
    {
        $identity = $this->identity($email);
        $secret = $this->twoFactor()->newSecret();
        $codes = $this->twoFactor()->confirm($identity, $secret, $this->twoFactor()->code($secret, intdiv(time(), 30)));

        return [$secret, $codes];
    }

    private function codeFor(string $secret, int $offset = 0): string
    {
        return $this->twoFactor()->code($secret, intdiv(time(), 30) + $offset);
    }

    public function test_codes_match_the_rfc_6238_test_vectors_and_tolerate_a_little_clock_drift(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';          // the RFC's "12345678901234567890" in base32 (HMAC-SHA1)
        $this->assertSame('287082', $this->twoFactor()->code($secret, intdiv(59, 30)));
        $this->assertSame('081804', $this->twoFactor()->code($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', $this->twoFactor()->code($secret, intdiv(1111111111, 30)));

        $step = intdiv(1111111109, 30);
        $this->assertSame($step, $this->twoFactor()->matchingStep($secret, '081804', null, 1111111109));
        $this->assertSame($step, $this->twoFactor()->matchingStep($secret, '081 804', null, 1111111109 + 30), 'one step late is fine, spaces are ignored');
        $this->assertNull($this->twoFactor()->matchingStep($secret, '081804', null, 1111111109 + 90), 'three steps late is not');
        $this->assertNull($this->twoFactor()->matchingStep($secret, '081804', $step, 1111111109), 'a code that was used is not accepted again');
        $this->assertNull($this->twoFactor()->matchingStep($secret, 'abcdef'));
    }

    public function test_setting_it_up_needs_a_correct_code_then_shows_the_recovery_codes_once(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);

        $this->post('/account/security/2fa/start')->assertRedirect();
        $secret = session('two_factor.setup_secret');
        $this->assertNotEmpty($secret);

        $page = $this->get('/security')->assertOk()->viewData('page')['props']['security'];
        $this->assertFalse($page['enabled']);
        $this->assertStringStartsWith('otpauth://totp/', $page['pending']['uri']);
        $this->assertStringContainsString("secret={$secret}", $page['pending']['uri']);

        $this->post('/account/security/2fa/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($this->twoFactor()->isEnabled($this->identity('owner@acme.test')));

        $this->post('/account/security/2fa/confirm', ['code' => $this->codeFor($secret)])->assertSessionHas('recovery_codes');
        $identity = $this->identity('owner@acme.test');
        $this->assertTrue($this->twoFactor()->isEnabled($identity));
        $this->assertSame(10, $this->twoFactor()->recoveryCodesLeft($identity));
        $this->assertStringNotContainsString($secret, (string) $identity->getRawOriginal('two_factor_secret'), 'the secret is stored encrypted');

        $shown = $this->get('/security')->viewData('page')['props']['security'];
        $this->assertCount(10, $shown['recovery_codes']);
        $this->assertNull($this->get('/security')->viewData('page')['props']['security']['recovery_codes'], 'only the first page after setup has them');
    }

    public function test_signing_in_asks_for_the_code_and_a_wrong_one_does_not_get_you_in(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        [$secret] = $this->enrol('owner@acme.test');

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->assertNull(session('identity_id'), 'the password alone does not open anything');
        $this->get('/two-factor-challenge')->assertOk();

        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $this->codeFor($secret, 1)])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertSame($acme->id, session('tenant_id'));
        $this->assertNull(session('two_factor.pending'));
    }

    public function test_a_code_works_only_once(): void
    {
        $this->company('Acme', 'owner@acme.test');
        [$secret] = $this->enrol('owner@acme.test');
        $code = $this->codeFor($secret, 1);                // newer than the one used to turn it on

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/');

        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_signs_you_in_once_and_five_wrong_codes_lock_the_step(): void
    {
        $this->company('Acme', 'owner@acme.test');
        [, $codes] = $this->enrol('owner@acme.test');

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => strtoupper($codes[0])])->assertRedirect('/');         // case and the dash do not matter
        $this->assertSame(9, $this->twoFactor()->recoveryCodesLeft($this->identity('owner@acme.test')));

        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');          // already used
        for ($i = 0; $i < 4; $i++) {
            $this->post('/two-factor-challenge', ['code' => 'wrong-'.$i]);
        }
        $this->post('/two-factor-challenge', ['code' => $codes[1]])->assertRedirect('/login');               // locked: even a good code is refused
        $this->assertGuest();
    }

    public function test_turning_it_off_and_making_new_recovery_codes_need_the_password(): void
    {
        $this->company('Acme', 'owner@acme.test');
        [, $codes] = $this->enrol('owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $codes[0]]);

        $this->post('/account/security/2fa/recovery-codes', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/account/security/2fa/disable', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertTrue($this->twoFactor()->isEnabled($this->identity('owner@acme.test')));

        $this->post('/account/security/2fa/recovery-codes', ['password' => self::PASSWORD])->assertSessionHas('recovery_codes');
        $this->assertSame(10, $this->twoFactor()->recoveryCodesLeft($this->identity('owner@acme.test')));
        $this->post('/account/security/2fa/disable', ['password' => self::PASSWORD])->assertSessionHas('success');
        $identity = $this->identity('owner@acme.test');
        $this->assertFalse($this->twoFactor()->isEnabled($identity));
        $this->assertNull($identity->two_factor_secret);
    }

    public function test_when_the_platform_requires_it_administrators_can_only_set_it_up_and_cannot_turn_it_off(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->get('/')->assertOk();                                     // optional to begin with

        app(SignInSecurity::class)->savePolicy('admins');
        $this->get('/contacts')->assertRedirect('/security');
        $this->withHeaders(['Referer' => config('app.url'), 'Origin' => config('app.url')])->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('two_factor_required', true);
        $this->get('/security')->assertOk()->assertSee('"required":true', false);
        $this->post('/account/security/2fa/start')->assertRedirect();                                // setting up stays open

        $secret = session('two_factor.setup_secret');
        $this->post('/account/security/2fa/confirm', ['code' => $this->codeFor($secret)]);
        $this->get('/contacts')->assertOk();

        $this->post('/account/security/2fa/disable', ['password' => self::PASSWORD])->assertSessionHas('warning');
        $this->assertTrue($this->twoFactor()->isEnabled($this->identity('owner@acme.test')));
    }

    public function test_an_administrators_only_policy_leaves_ordinary_users_alone_and_everyone_catches_them(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->run(fn () => User::factory()->create(['central_user_id' => AccountUser::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => self::PASSWORD])->id, 'role' => 'user', 'email' => 'ann@acme.test']));
        $this->identity('ann@acme.test')->tenants()->attach($acme->id);

        $this->post('/login', ['email' => 'ann@acme.test', 'password' => self::PASSWORD]);
        app(SignInSecurity::class)->savePolicy('admins');
        $this->get('/contacts')->assertOk();
        app(SignInSecurity::class)->savePolicy('everyone');
        $this->get('/contacts')->assertRedirect('/security');
    }

    public function test_the_platform_area_requires_it_too_and_only_platform_administrators_can_change_the_policy(): void
    {
        AccountUser::create(['name' => 'Platform', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->patch('/platform/settings/security', ['policy' => 'nonsense'])->assertSessionHasErrors('policy');
        $this->patch('/platform/settings/security', ['policy' => 'platform_admins'])->assertSessionHas('success');
        $this->assertSame('platform_admins', app(SignInSecurity::class)->policy());

        $this->get('/platform')->assertRedirect('/platform/security');
        $this->get('/platform/security')->assertOk();

        $this->company('Acme', 'owner@acme.test');
        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
        $this->patch('/platform/settings/security', ['policy' => 'optional'])->assertForbidden();      // not a platform administrator
        $this->assertSame('platform_admins', app(SignInSecurity::class)->policy());
    }

    public function test_api_tokens_need_the_code_for_people_who_use_it(): void
    {
        $this->company('Acme', 'owner@acme.test');
        [$secret] = $this->enrol('owner@acme.test');
        $body = ['email' => 'owner@acme.test', 'password' => self::PASSWORD];

        $this->postJson('/api/v1/auth/token', $body)->assertStatus(422)->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/v1/auth/token', $body + ['otp' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/token', $body + ['otp' => $this->codeFor($secret, 1)])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_company_administrator_can_reset_a_colleague_but_not_a_platform_administrator(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->run(fn () => User::factory()->create(['central_user_id' => AccountUser::create(['name' => 'Ann', 'email' => 'ann@acme.test', 'password' => self::PASSWORD])->id, 'role' => 'user', 'email' => 'ann@acme.test']));
        $this->identity('ann@acme.test')->tenants()->attach($acme->id);
        $this->enrol('ann@acme.test');
        $annId = $acme->run(fn () => User::where('email', 'ann@acme.test')->value('id'));

        $this->bearerFor('owner@acme.test', self::PASSWORD);
        $row = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->firstWhere('email', 'ann@acme.test');
        $this->assertTrue($row['two_factor']);
        $this->assertArrayNotHasKey('central_user_id', $row);

        $this->postJson("/api/v1/users/{$annId}/reset-two-factor")->assertOk();
        $this->assertFalse($this->twoFactor()->isEnabled($this->identity('ann@acme.test')));

        $this->bearerFor('ann@acme.test', self::PASSWORD);
        $this->postJson("/api/v1/users/{$annId}/reset-two-factor")->assertForbidden();         // not an administrator
    }

    public function test_platform_administrators_can_reset_two_step_for_administrators_and_company_people(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->enrol('owner@acme.test');
        $other = AccountUser::create(['name' => 'Other', 'email' => 'other@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->enrol('other@x.test');
        $me = AccountUser::create(['name' => 'Platform', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $list = $this->get('/platform/administrators')->viewData('page')['props']['administrators'];
        $this->assertTrue(collect($list)->firstWhere('email', 'other@x.test')['two_factor']);

        $this->post("/platform/administrators/{$me->id}/reset-two-factor")->assertStatus(422);             // your own: use your own page
        $this->post("/platform/administrators/{$other->id}/reset-two-factor")->assertSessionHas('success');
        $this->assertFalse($this->twoFactor()->isEnabled($this->identity('other@x.test')));

        $ownerId = $acme->run(fn () => User::where('email', 'owner@acme.test')->value('id'));
        $this->assertTrue(collect($this->get("/platform/companies/{$acme->id}/users")->viewData('page')['props']['users'])->firstWhere('email', 'owner@acme.test')['two_factor']);
        $this->post("/platform/companies/{$acme->id}/users/{$ownerId}/reset-two-factor")->assertSessionHas('success');
        $this->assertFalse($this->twoFactor()->isEnabled($this->identity('owner@acme.test')));
    }

    public function test_api_tokens_are_refused_to_people_who_must_set_it_up_first(): void
    {
        $this->company('Acme', 'owner@acme.test');
        app(SignInSecurity::class)->savePolicy('admins');
        PlatformSetting::forgetCache();

        $this->postJson('/api/v1/auth/token', ['email' => 'owner@acme.test', 'password' => self::PASSWORD])->assertForbidden();
    }
}
