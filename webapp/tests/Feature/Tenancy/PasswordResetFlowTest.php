<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/** Getting back in: ask for a link, open it from the e-mail, choose a new password. */
class PasswordResetFlowTest extends TenantTestCase
{
    public function test_the_page_opens_with_the_email_from_the_sign_in_step(): void
    {
        $this->get('/forgot-password?email=ana%40acme.test')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/ForgotPassword')->where('email', 'ana@acme.test'));
    }

    public function test_the_answer_is_the_same_for_a_known_and_an_unknown_address(): void
    {
        $this->company('Acme', 'owner@acme.test');
        Notification::fake();

        $known = $this->post('/forgot-password', ['email' => 'owner@acme.test']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@nowhere.test']);

        $this->assertSame($known->getSession()->get('success'), $unknown->getSession()->get('success'));
        Notification::assertSentToTimes(AccountUser::where('email', 'owner@acme.test')->first(), ResetPassword::class, 1);
        Notification::assertCount(1);
    }

    public function test_the_e_mail_is_in_the_platforms_name_with_one_button_and_the_expiry(): void
    {
        PlatformSetting::put('login.name', 'Acme CRM');
        PlatformSetting::forgetCache();
        $this->company('Acme', 'owner@acme.test');
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'owner@acme.test']);

        Notification::assertSentTo(AccountUser::where('email', 'owner@acme.test')->first(), ResetPassword::class, function (ResetPassword $n, array $channels) {
            $mail = $n->toMail(AccountUser::where('email', 'owner@acme.test')->first());

            return $mail->subject === 'Choose a new password for Acme CRM'
                && $mail->viewData['button'][0] === 'Choose a new password'
                && str_contains($mail->viewData['button'][1], '/reset-password/'.$n->token.'?email=owner%40acme.test')
                && str_contains(implode(' ', $mail->viewData['lines']), 'expires in 60 minutes');
        });
    }

    public function test_a_new_password_is_chosen_from_the_link_once_and_the_old_one_stops_working(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $identity = AccountUser::where('email', 'owner@acme.test')->first();
        $token = app('auth.password.broker')->createToken($identity);

        $this->get("/reset-password/{$token}?email=owner%40acme.test")->assertOk()->assertInertia(fn ($p) => $p->component('Auth/ResetPassword')->where('email', 'owner@acme.test'));

        $this->post('/reset-password', ['token' => $token, 'email' => 'owner@acme.test', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => 'owner@acme.test', 'password' => 'Brand-New-Passw0rd', 'password_confirmation' => 'Brand-New-Passw0rd'])
            ->assertRedirect('/login')->assertSessionHas('success');

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Brand-New-Passw0rd'])->assertRedirect('/');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/reset-password', ['token' => $token, 'email' => 'owner@acme.test', 'password' => 'Third-Passw0rd-x', 'password_confirmation' => 'Third-Passw0rd-x'])->assertSessionHasErrors('email');
    }
}
