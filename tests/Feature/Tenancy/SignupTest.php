<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Signup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\SignupSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\SentMessage;
use Tests\TenantTestCase;

/** Self-service sign-up: ask, confirm the e-mail, choose a password, and only then does a company exist. */
class SignupTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
        RateLimiter::clear('signup-email:'.sha1('ana@acme.test'));
    }

    private function mode(string $mode): void
    {
        app(SignupSettings::class)->save($mode);
        PlatformSetting::forgetCache();
    }

    private function platformAdmin(): void
    {
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return $over + ['company' => 'Acme Bakery', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => 'ana@acme.test', 'accept_terms' => true, 'timezone' => 'Asia/Manila'];
    }

    /** @return list<SentMessage> */
    private function sent(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->all();
    }

    /** The newest e-mail to this address, with the sign-up link in it (if any). */
    private function mailTo(string $email): ?array
    {
        foreach (array_reverse($this->sent()) as $sent) {
            $message = $sent->getOriginalMessage();
            if (collect($message->getTo())->contains(fn ($a) => $a->getAddress() === $email)) {
                $body = (string) ($message->getHtmlBody() ?: $message->getTextBody());

                return ['subject' => $message->getSubject(), 'body' => $body, 'token' => preg_match('~/signup/([A-Za-z0-9]{48})~', $body, $m) ? $m[1] : null];
            }
        }

        return null;
    }

    public function test_sign_ups_are_closed_until_the_platform_opens_them(): void
    {
        $this->get('/signup')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Signup')->where('mode', 'closed'));
        $this->post('/signup', $this->form())->assertForbidden();
        $this->assertSame(0, Signup::count());

        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.signup', 'closed'));         // no "Create an account" link
        $this->mode('open');
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.signup', 'open'));
    }

    public function test_asking_for_a_workspace_creates_nothing_until_the_e_mail_is_confirmed(): void
    {
        $this->mode('open');

        $this->post('/signup', $this->form())->assertRedirect('/signup/sent');
        $this->get('/signup/sent')->assertInertia(fn ($p) => $p->where('mode', 'sent')->where('email', 'ana@acme.test'));

        $signup = Signup::firstOrFail();
        $this->assertSame([Signup::PENDING_EMAIL, 'Acme Bakery', 'Asia/Manila'], [$signup->status, $signup->company_name, $signup->timezone]);
        $this->assertSame(0, Tenant::where('slug', 'acme-bakery')->count());
        $this->assertNull(AccountUser::where('email', 'ana@acme.test')->first());

        $mail = $this->mailTo('ana@acme.test');
        $this->assertStringContainsString('Confirm your e-mail', $mail['subject']);
        $this->assertSame($signup->token_hash, hash('sha256', $mail['token']));                       // only the hash is stored
    }

    public function test_the_form_is_checked_and_bots_and_throw_away_addresses_are_turned_away(): void
    {
        $this->mode('open');

        $this->post('/signup', $this->form(['accept_terms' => false]))->assertSessionHasErrors('accept_terms');
        $this->post('/signup', $this->form(['email' => 'not-an-address']))->assertSessionHasErrors('email');
        $this->post('/signup', $this->form(['email' => 'x@mailinator.com']))->assertSessionHasErrors('email');
        $this->post('/signup', $this->form(['phone' => 'call me']))->assertSessionHasErrors('phone');
        $this->post('/signup', $this->form(['company' => '']))->assertSessionHasErrors('company');

        $this->post('/signup', $this->form(['website' => 'http://spam.example']))->assertRedirect('/signup/sent');       // thanked like anybody
        $this->assertSame(0, Signup::count(), 'a filled honeypot stores nothing');
        $this->assertNull($this->mailTo('ana@acme.test'));
    }

    public function test_someone_who_already_has_an_account_is_told_so_by_e_mail_and_nothing_is_created(): void
    {
        $this->mode('open');
        AccountUser::create(['name' => 'Ana', 'email' => 'ana@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->post('/signup', $this->form())->assertRedirect('/signup/sent');                  // the page cannot reveal it

        $this->assertSame(0, Signup::count());
        $this->assertStringContainsString('already have an account', $this->mailTo('ana@acme.test')['subject']);
    }

    public function test_asking_again_sends_a_fresh_link_but_never_more_than_one_e_mail_a_minute(): void
    {
        $this->mode('open');
        $this->post('/signup', $this->form());
        $first = $this->mailTo('ana@acme.test')['token'];

        $this->post('/signup', $this->form());                                                  // a double click
        $this->assertCount(1, array_filter($this->sent(), fn ($s) => collect($s->getOriginalMessage()->getTo())->contains(fn ($a) => $a->getAddress() === 'ana@acme.test')));

        $this->travel(2)->minutes();
        $this->post('/signup', $this->form());
        $second = $this->mailTo('ana@acme.test')['token'];

        $this->assertNotSame($first, $second);
        $this->assertSame(1, Signup::count(), 'the same request, not a second one');
        $this->get("/signup/{$first}")->assertInertia(fn ($p) => $p->component('Auth/SignupStatus')->where('state', 'invalid'));     // the old link is dead
        $this->get("/signup/{$second}")->assertInertia(fn ($p) => $p->component('Auth/SignupComplete'));
    }

    public function test_the_link_leads_to_choosing_a_password_and_unknown_or_expired_links_are_refused(): void
    {
        $this->mode('open');
        $this->post('/signup', $this->form());
        $token = $this->mailTo('ana@acme.test')['token'];

        $this->get('/signup/'.str_repeat('a', 48))->assertInertia(fn ($p) => $p->component('Auth/SignupStatus')->where('state', 'invalid'));
        $this->get("/signup/{$token}")->assertInertia(fn ($p) => $p->component('Auth/SignupComplete')->where('company', 'Acme Bakery')->where('email', 'ana@acme.test'));
        $this->assertSame(Signup::VERIFIED, Signup::first()->status);

        $this->travel(4)->days();
        $this->get("/signup/{$token}")->assertInertia(fn ($p) => $p->component('Auth/SignupStatus')->where('state', 'invalid'));
        $this->post("/signup/{$token}", ['password' => 'Bakery-Passw0rd-1', 'password_confirmation' => 'Bakery-Passw0rd-1'])->assertRedirect("/signup/{$token}");
        $this->assertSame(0, Tenant::where('slug', 'acme-bakery')->count());
    }

    public function test_choosing_a_password_creates_the_company_and_signs_the_person_in_as_its_administrator(): void
    {
        $this->mode('open');
        $this->post('/signup', $this->form());
        $token = $this->mailTo('ana@acme.test')['token'];
        $this->get("/signup/{$token}");

        $this->post("/signup/{$token}", ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post("/signup/{$token}", ['password' => 'Bakery-Passw0rd-1', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertSame(0, Tenant::where('slug', 'acme-bakery')->count());

        $this->post("/signup/{$token}", ['password' => 'Bakery-Passw0rd-1', 'password_confirmation' => 'Bakery-Passw0rd-1'])->assertRedirect('/');

        $tenant = Tenant::where('slug', 'acme-bakery')->firstOrFail();
        $this->assertSame('Acme Bakery', $tenant->name);
        $this->assertTrue($tenant->isActive());
        $this->assertSame(['Ana Reyes', 'admin'], $tenant->run(fn () => [User::first()->name, User::first()->role]));
        $this->assertSame('Asia/Manila', $tenant->run(fn () => Setting::get('app.timezone')));
        $this->assertNotNull(AccountUser::where('email', 'ana@acme.test')->value('email_verified_at'));
        $this->assertSame([Signup::COMPLETED, $tenant->getTenantKey()], [Signup::first()->status, Signup::first()->tenant_id]);
        $this->assertSame($tenant->getTenantKey(), session('tenant_id'));
        $this->assertStringContainsString('is ready', $this->mailTo('ana@acme.test')['subject']);

        // the link works once
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get("/signup/{$token}")->assertRedirect('/login');
    }

    public function test_two_companies_with_the_same_name_get_different_addresses(): void
    {
        $this->mode('open');
        foreach (['ana@acme.test', 'ben@acme.test'] as $email) {
            $this->post('/signup', $this->form(['email' => $email, 'first_name' => strtok($email, '@')]));
            $token = $this->mailTo($email)['token'];
            $this->get("/signup/{$token}");
            $this->post("/signup/{$token}", ['password' => 'Bakery-Passw0rd-1', 'password_confirmation' => 'Bakery-Passw0rd-1']);
            $this->app['auth']->forgetGuards();
            $this->flushSession();
        }

        $slugs = Tenant::where('name', 'Acme Bakery')->pluck('slug');
        $this->assertCount(2, $slugs);
        $this->assertCount(2, $slugs->unique());
    }

    public function test_in_approval_mode_a_platform_administrator_decides_before_anything_is_created(): void
    {
        $this->mode('approval');
        $this->platformAdmin();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->post('/signup', $this->form());
        $token = $this->mailTo('ana@acme.test')['token'];
        $this->get("/signup/{$token}")->assertInertia(fn ($p) => $p->component('Auth/SignupStatus')->where('state', 'pending_approval'));
        $this->assertSame(Signup::PENDING_APPROVAL, Signup::first()->status);
        $this->assertStringContainsString('waiting for approval', $this->mailTo('platform@x.test')['subject']);       // the administrator is told
        $this->post("/signup/{$token}", ['password' => 'Bakery-Passw0rd-1', 'password_confirmation' => 'Bakery-Passw0rd-1'])->assertRedirect("/signup/{$token}");
        $this->assertSame(0, Tenant::where('slug', 'acme-bakery')->count());

        $this->platformAdmin();
        $id = Signup::first()->id;
        $this->get('/platform/signups')->assertInertia(fn ($p) => $p->component('Platform/Signups')->where('counts.waiting', 1));
        $this->post("/platform/signups/{$id}/approve")->assertRedirect()->assertSessionHas('success');

        $approved = $this->mailTo('ana@acme.test');
        $this->assertStringContainsString('approved', $approved['subject']);
        $this->assertNotSame($token, $approved['token']);                                                // a new link
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post("/signup/{$approved['token']}", ['password' => 'Bakery-Passw0rd-1', 'password_confirmation' => 'Bakery-Passw0rd-1'])->assertRedirect('/');
        $this->assertSame(1, Tenant::where('slug', 'acme-bakery')->count());
    }

    public function test_a_declined_request_is_told_politely_and_cannot_be_completed(): void
    {
        $this->mode('approval');
        $this->post('/signup', $this->form());
        $token = $this->mailTo('ana@acme.test')['token'];
        $this->get("/signup/{$token}");

        $this->platformAdmin();
        $id = Signup::first()->id;
        $this->post("/platform/signups/{$id}/reject", ['note' => 'Not a fit right now.'])->assertRedirect();
        $this->post("/platform/signups/{$id}/approve")->assertStatus(422);                           // a decision is final

        $this->assertSame([Signup::REJECTED, 'Not a fit right now.'], [Signup::first()->status, Signup::first()->decision_note]);
        $this->assertStringContainsString('Not a fit right now.', $this->mailTo('ana@acme.test')['body']);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get("/signup/{$token}")->assertInertia(fn ($p) => $p->component('Auth/SignupStatus')->where('state', 'rejected'));
    }

    public function test_only_platform_administrators_manage_sign_ups(): void
    {
        $this->mode('open');
        $this->get('/platform/signups')->assertRedirect('/login');

        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get('/platform/signups')->assertForbidden();
        $this->patch('/platform/signups/mode', ['mode' => 'closed'])->assertForbidden();
        $this->assertSame('open', app(SignupSettings::class)->mode());

        $this->platformAdmin();
        $this->patch('/platform/signups/mode', ['mode' => 'nonsense'])->assertSessionHasErrors('mode');
        $this->patch('/platform/signups/mode', ['mode' => 'approval'])->assertRedirect()->assertSessionHas('success');
        PlatformSetting::forgetCache();
        $this->assertSame('approval', app(SignupSettings::class)->mode());
    }
}
