<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Services\Tenancy\MailSettings;
use App\Services\Tenancy\PlatformNotifier;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** Who the platform e-mails about requests and messages, and the e-mail server override. */
class PlatformSettingsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function platformAdmin(string $email = 'platform@x.test', string $name = 'Platform Admin'): AccountUser
    {
        $admin = AccountUser::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => $email, 'password' => 'Platform-Passw0rd-1']);

        return $admin;
    }

    public function test_by_default_every_active_administrator_is_told_at_their_own_address(): void
    {
        $this->platformAdmin('one@x.test');
        AccountUser::create(['name' => 'Two', 'email' => 'two@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        AccountUser::create(['name' => 'Gone', 'email' => 'gone@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true, 'status' => 'suspended']);

        $notifier = app(PlatformNotifier::class);

        $this->assertEqualsCanonicalizing(['one@x.test', 'two@x.test'], $notifier->recipients(PlatformNotifier::REQUESTS));
        $this->assertEqualsCanonicalizing(['one@x.test', 'two@x.test'], $notifier->recipients(PlatformNotifier::MESSAGES));
    }

    public function test_each_administrator_chooses_topics_and_an_address(): void
    {
        $one = $this->platformAdmin('one@x.test');
        $two = AccountUser::create(['name' => 'Two', 'email' => 'two@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);

        $this->patch('/platform/settings/notifications', ['administrators' => [
            ['id' => $one->id, 'notify_requests' => true, 'notify_messages' => false, 'notify_email' => 'alerts@x.test'],
            ['id' => $two->id, 'notify_requests' => false, 'notify_messages' => true, 'notify_email' => ''],
        ]])->assertRedirect()->assertSessionHas('success');

        $notifier = app(PlatformNotifier::class);
        $this->assertSame(['alerts@x.test'], $notifier->recipients(PlatformNotifier::REQUESTS));
        $this->assertSame(['two@x.test'], $notifier->recipients(PlatformNotifier::MESSAGES));
    }

    public function test_a_bad_notification_address_is_refused(): void
    {
        $one = $this->platformAdmin('one@x.test');

        $this->patch('/platform/settings/notifications', ['administrators' => [
            ['id' => $one->id, 'notify_requests' => true, 'notify_messages' => true, 'notify_email' => 'not-an-address'],
        ]])->assertSessionHasErrors('administrators.0.notify_email');
    }

    public function test_a_request_is_created_and_the_notifier_names_the_chosen_address(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $one = $this->platformAdmin('one@x.test');
        $one->update(['notify_email' => 'alerts@x.test', 'notify_messages' => false]);

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'acme'])->json('token');
        $this->defaultHeaders = [];
        $client = $this->withToken($token);

        $client->postJson('/api/v1/limits/request', ['kind' => 'data_objects', 'requested_limit' => $acme->max_data_objects + 2])->assertCreated();
        $this->assertSame(['alerts@x.test'], app(PlatformNotifier::class)->recipients(PlatformNotifier::REQUESTS));
        $this->assertSame([], app(PlatformNotifier::class)->recipients(PlatformNotifier::MESSAGES));
    }

    public function test_the_mail_server_override_replaces_the_env_values_and_keeps_the_password_encrypted(): void
    {
        $this->platformAdmin();
        config(['mail.mailers.smtp.host' => 'env.example.test', 'mail.mailers.smtp.port' => 2525, 'mail.from.address' => 'env@example.test']);

        $this->patch('/platform/settings/mail', [
            'enabled' => true, 'host' => 'smtp.custom.test', 'port' => 465, 'username' => 'mailer', 'password' => 'Sup3r-Secret', 'scheme' => 'smtps',
            'from_address' => 'platform@custom.test', 'from_name' => 'Platform',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertNotSame('Sup3r-Secret', PlatformSetting::get('mail.password'));
        PlatformSetting::forgetCache();
        app(MailSettings::class)->apply();

        $this->assertSame(['smtp', 'smtp.custom.test', 465, 'mailer', 'Sup3r-Secret', 'smtps', 'platform@custom.test'], [
            config('mail.default'), config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.username'),
            config('mail.mailers.smtp.password'), config('mail.mailers.smtp.scheme'), config('mail.from.address'),
        ]);
    }

    public function test_the_saved_password_is_never_sent_to_the_page_and_an_empty_one_keeps_it(): void
    {
        $this->platformAdmin();
        $this->patch('/platform/settings/mail', ['enabled' => true, 'host' => 'smtp.custom.test', 'password' => 'Sup3r-Secret', 'scheme' => 'smtp']);

        $this->get('/platform/settings/mail')->assertOk()->assertDontSee('Sup3r-Secret')->assertInertia(fn ($page) => $page->where('mail.password_set', true)->missing('mail.password'));
        $this->patch('/platform/settings/mail', ['enabled' => true, 'host' => 'smtp.custom.test', 'password' => '', 'scheme' => 'smtp']);

        PlatformSetting::forgetCache();
        $this->assertTrue((bool) PlatformSetting::get('mail.password'));
    }

    public function test_switching_the_override_off_leaves_the_env_values_in_charge(): void
    {
        $this->platformAdmin();
        config(['mail.mailers.smtp.host' => 'env.example.test']);
        $this->patch('/platform/settings/mail', ['enabled' => false, 'host' => 'smtp.custom.test', 'scheme' => 'smtp'])->assertRedirect();

        PlatformSetting::forgetCache();
        app(MailSettings::class)->apply();

        $this->assertSame('env.example.test', config('mail.mailers.smtp.host'));
    }

    public function test_resetting_the_mail_server_clears_everything_saved_but_keeps_the_sendgrid_event_settings(): void
    {
        $this->platformAdmin();
        config(['mail.mailers.smtp.host' => 'env.example.test']);
        $this->patch('/platform/settings/mail', ['enabled' => true, 'host' => 'smtp.custom.test', 'port' => 465, 'username' => 'mailer', 'password' => 'Sup3r-Secret', 'scheme' => 'smtps', 'from_address' => 'platform@custom.test', 'from_name' => 'Platform']);
        app(MailSettings::class)->sendgridUrl();
        $token = PlatformSetting::get('mail.sendgrid_token');

        $this->post('/platform/settings/mail/reset')->assertRedirect()->assertSessionHas('success');

        PlatformSetting::forgetCache();
        $this->assertSame(
            ['0', null, null, null, null, 'smtp', null, null],
            array_map(fn ($key) => PlatformSetting::get("mail.{$key}"), ['enabled', 'host', 'port', 'username', 'password', 'scheme', 'from_address', 'from_name']),
        );
        $this->assertSame($token, PlatformSetting::get('mail.sendgrid_token'));
        app(MailSettings::class)->apply();
        $this->assertSame('env.example.test', config('mail.mailers.smtp.host'));
    }

    public function test_only_a_platform_administrator_can_reset_the_mail_server(): void
    {
        $this->post('/platform/settings/mail/reset')->assertRedirect('/login');
    }

    public function test_a_host_is_required_when_the_override_is_on(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/settings/mail', ['enabled' => true, 'host' => '', 'scheme' => 'smtp'])->assertSessionHasErrors('host');
    }

    public function test_only_platform_administrators_reach_the_settings(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->flushSession();

        $this->get('/platform/settings')->assertRedirect();
        $this->get('/platform/settings/notifications')->assertRedirect();
        $this->get('/platform/settings/mail')->assertRedirect();
        $this->patch('/platform/settings/mail', ['enabled' => false, 'scheme' => 'smtp'])->assertRedirect();
        $this->assertNull(PlatformSetting::get('mail.enabled'));
    }

    public function test_failed_automation_e_mails_are_opt_in_and_use_the_chosen_address(): void
    {
        $one = $this->platformAdmin('one@x.test');
        $notifier = app(PlatformNotifier::class);
        $this->assertSame([], $notifier->recipients(PlatformNotifier::AUTOMATION_FAILURES), 'off until an administrator ticks it');

        $this->patch('/platform/settings/notifications', ['administrators' => [
            ['id' => $one->id, 'notify_requests' => true, 'notify_messages' => true, 'notify_automation_failures' => true, 'notify_email' => 'ops@x.test'],
        ]])->assertRedirect();

        $this->assertSame(['ops@x.test'], $notifier->recipients(PlatformNotifier::AUTOMATION_FAILURES));
        $this->assertSame(['ops@x.test'], $notifier->recipients(PlatformNotifier::REQUESTS));
    }

    public function test_the_overview_and_each_sub_page_render_for_a_platform_administrator(): void
    {
        $this->platformAdmin();

        $this->get('/platform/settings')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Settings')->has('notifications.administrators')->has('mail.custom')->has('services.companies'));
        $this->get('/platform/settings/notifications')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/SettingsNotifications')->has('administrators'));
        $this->get('/platform/settings/mail')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/SettingsMail')->has('mail')->has('env'));
        $this->get('/platform/services')->assertOk();
    }
}
