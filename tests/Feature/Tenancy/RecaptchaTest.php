<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Signup;
use App\Services\Tenancy\Recaptcha;
use App\Services\Tenancy\SignupSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TenantTestCase;

/** reCAPTCHA v3 on the sign-in and sign-up pages: off until the platform saves both keys, then every attempt needs a good score. */
class RecaptchaTest extends TenantTestCase
{
    private function turnOn(float $threshold = 0.5): void
    {
        app(Recaptcha::class)->save(['enabled' => true, 'site_key' => '6Lc_test-site-key', 'secret' => 'test-secret', 'threshold' => $threshold]);
        PlatformSetting::forgetCache();
    }

    private function google(array $answer, int $status = 200): void
    {
        Http::fake([Recaptcha::VERIFY_URL => Http::response($answer, $status)]);
    }

    private function login(array $extra = [])
    {
        return $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'] + $extra);
    }

    public function test_it_is_off_until_both_keys_are_saved_and_nothing_changes_while_it_is_off(): void
    {
        $this->company('Acme', 'owner@acme.test');
        Http::fake();

        $this->login()->assertRedirect();                                                             // no token needed
        Http::assertNothingSent();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.recaptcha_site_key', null));

        app(Recaptcha::class)->save(['enabled' => true, 'site_key' => '6Lc_test-site-key', 'threshold' => 0.5]);       // no secret yet
        PlatformSetting::forgetCache();
        $this->assertFalse(app(Recaptcha::class)->active());
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.recaptcha_site_key', null));
    }

    public function test_the_site_key_reaches_the_pages_but_the_secret_never_does(): void
    {
        $this->turnOn();

        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.recaptcha_site_key', '6Lc_test-site-key'));
        $this->get('/signup')->assertOk();
        $this->assertStringNotContainsString('test-secret', $this->get('/login')->getContent());
        $this->assertNotSame('test-secret', PlatformSetting::get('recaptcha.secret'), 'stored encrypted');
        $this->assertArrayNotHasKey('secret', app(Recaptcha::class)->current());
    }

    public function test_signing_in_needs_a_good_score_for_the_login_action(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->turnOn();

        $this->login()->assertSessionHasErrors('email');                                              // no token at all
        $this->assertGuest();

        $this->google(['success' => true, 'score' => 0.9, 'action' => 'signup']);
        $this->login(['recaptcha_token' => 'abc'])->assertSessionHasErrors('email');                  // a token made for another action

        $this->google(['success' => true, 'score' => 0.2, 'action' => 'login']);
        $this->login(['recaptcha_token' => 'abc'])->assertSessionHasErrors('email');                  // looks like a script

        $this->google(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
        $this->login(['recaptcha_token' => 'abc'])->assertSessionHasErrors('email');

        $this->google(['success' => true, 'score' => 0.9, 'action' => 'login']);
        $this->login(['recaptcha_token' => 'abc'])->assertRedirect();
        $this->assertAuthenticated();
        Http::assertSent(fn ($r) => $r['secret'] === 'test-secret' && $r['response'] === 'abc');
    }

    public function test_a_refused_check_is_not_a_wrong_password_and_never_locks_anybody_out(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->turnOn();
        $this->google(['success' => false]);
        foreach (range(1, 8) as $i) {
            $this->login(['recaptcha_token' => 'bad'])->assertSessionHasErrors('email');
        }

        $this->google(['success' => true, 'score' => 0.9, 'action' => 'login']);
        $this->login(['recaptcha_token' => 'good'])->assertRedirect();                               // still signs in: nothing was counted
        $this->assertAuthenticated();
    }

    public function test_the_strictness_is_the_platforms_to_set(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->turnOn(0.8);

        $this->google(['success' => true, 'score' => 0.7, 'action' => 'login']);
        $this->login(['recaptcha_token' => 'abc'])->assertSessionHasErrors('email');
        $this->google(['success' => true, 'score' => 0.8, 'action' => 'login']);
        $this->login(['recaptcha_token' => 'abc'])->assertRedirect();
    }

    public function test_a_google_outage_does_not_lock_people_out(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->turnOn();

        $this->google([], 503);
        $this->login(['recaptcha_token' => 'abc'])->assertRedirect();
        $this->assertAuthenticated();

        $this->post('/logout');
        Http::fake([Recaptcha::VERIFY_URL => fn () => throw new ConnectionException('offline')]);
        $this->login(['recaptcha_token' => 'abc'])->assertRedirect();
    }

    public function test_signing_up_needs_a_good_score_for_the_signup_action(): void
    {
        app(SignupSettings::class)->save('open');
        PlatformSetting::forgetCache();
        $this->turnOn();
        RateLimiter::clear('signup-email:'.sha1('ana@acme.test'));
        $form = ['company' => 'Acme Bakery', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => 'ana@acme.test', 'accept_terms' => true];

        $this->post('/signup', $form)->assertSessionHasErrors('recaptcha');
        $this->google(['success' => true, 'score' => 0.9, 'action' => 'login']);
        $this->post('/signup', $form + ['recaptcha_token' => 'abc'])->assertSessionHasErrors('recaptcha');
        $this->assertSame(0, Signup::count());

        $this->google(['success' => true, 'score' => 0.9, 'action' => 'signup']);
        $this->post('/signup', $form + ['recaptcha_token' => 'abc'])->assertRedirect('/signup/sent');
        $this->assertSame(1, Signup::count());
    }

    public function test_only_platform_administrators_set_it_up_and_the_keys_are_checked(): void
    {
        $this->get('/platform/settings/bot-protection')->assertRedirect('/login');
        $this->company('Acme', 'owner@acme.test');
        $this->login()->assertRedirect();
        $this->get('/platform/settings/bot-protection')->assertForbidden();
        $this->patch('/platform/settings/bot-protection', ['enabled' => true, 'site_key' => 'x', 'threshold' => 0.5])->assertForbidden();

        $this->post('/logout');
        AccountUser::create(['name' => 'Boss', 'email' => 'boss@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'boss@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->patch('/platform/settings/bot-protection', ['enabled' => true, 'site_key' => '', 'threshold' => 0.5])->assertSessionHasErrors('site_key');
        $this->patch('/platform/settings/bot-protection', ['enabled' => true, 'site_key' => 'has spaces!', 'threshold' => 0.5])->assertSessionHasErrors('site_key');
        $this->patch('/platform/settings/bot-protection', ['enabled' => true, 'site_key' => '6Lc_key', 'threshold' => 2])->assertSessionHasErrors('threshold');

        $this->patch('/platform/settings/bot-protection', ['enabled' => true, 'site_key' => '6Lc_key', 'threshold' => 0.5])->assertSessionHas('success', 'Saved, but it stays off until the secret key is saved too.');
        $this->patch('/platform/settings/bot-protection', ['enabled' => true, 'site_key' => '6Lc_key', 'secret' => 's3cret', 'threshold' => 0.6])->assertSessionHas('success', 'Bot protection is on for the sign-in and sign-up pages.');
        PlatformSetting::forgetCache();
        $this->assertTrue(app(Recaptcha::class)->active());
        $this->get('/platform/settings/bot-protection')->assertInertia(fn ($p) => $p->where('recaptcha.secret_set', true)->where('recaptcha.threshold', 0.6)->missing('recaptcha.secret'));

        $this->patch('/platform/settings/bot-protection', ['enabled' => true, 'site_key' => '6Lc_key', 'clear_secret' => true, 'threshold' => 0.6]);
        PlatformSetting::forgetCache();
        $this->assertFalse(app(Recaptcha::class)->active(), 'removing the secret switches it off');
    }
}
