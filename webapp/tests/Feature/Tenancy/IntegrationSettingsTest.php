<?php

namespace Tests\Feature\Tenancy;

use App\Integrations\Twilio\TwilioClient;
use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\Integration;
use App\Models\PlatformSetting;
use App\Services\Messaging\MessageComposer;
use App\Services\Tenancy\CallSettings;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\MailSettings;
use App\Services\Tenancy\SmsSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TenantTestCase;

/** Platform > Settings > SMS and Calls: the integrations every company can use, and how companies pick them up. */
class IntegrationSettingsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function api(string $email, string $company, string $password = 'Owner-Passw0rd-1'): static
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $password, 'company' => $company])->json('token');
        $client = $this->withToken($token);
        $this->defaultHeaders = [];

        return $client->withToken($token);
    }

    private function platformAdmin(): void
    {
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    private function savedSms(array $over = []): array
    {
        return $over + ['enabled' => true, 'account_sid' => 'ACplatform', 'auth_token' => 'platform-token', 'from_number' => '+15550001111', 'messaging_service_sid' => ''];
    }

    public function test_the_platform_saves_its_twilio_account_and_the_token_never_comes_back(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/settings/sms', $this->savedSms())->assertRedirect()->assertSessionHas('success');

        $this->assertNotSame('platform-token', PlatformSetting::get('sms.auth_token'), 'stored encrypted');
        $this->get('/platform/settings/sms')->assertOk()->assertDontSee('platform-token')
            ->assertInertia(fn ($p) => $p->component('Platform/SettingsSms')->where('sms.account_sid', 'ACplatform')->where('sms.token_set', true)->missing('sms.auth_token')->where('ready', true));

        $this->patch('/platform/settings/sms', $this->savedSms(['auth_token' => '']))->assertRedirect();             // an empty token keeps the saved one
        PlatformSetting::forgetCache();
        $this->assertTrue((bool) app(SmsSettings::class)->account());
    }

    public function test_the_account_is_incomplete_without_a_sender_or_when_switched_off(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/settings/sms', $this->savedSms(['from_number' => '']))->assertRedirect();
        PlatformSetting::forgetCache();
        $this->assertNull(app(SmsSettings::class)->account(), 'a number or a messaging service is needed');

        $this->patch('/platform/settings/sms', $this->savedSms(['messaging_service_sid' => 'MGservice', 'from_number' => '']))->assertRedirect();
        PlatformSetting::forgetCache();
        $this->assertSame('MGservice', app(SmsSettings::class)->account()['service']);

        $this->patch('/platform/settings/sms', ['enabled' => false])->assertRedirect();
        PlatformSetting::forgetCache();
        $this->assertNull(app(SmsSettings::class)->account());
    }

    public function test_a_number_must_look_like_a_phone_number(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/settings/sms', $this->savedSms(['from_number' => 'not a number']))->assertSessionHasErrors('from_number');
        $this->patch('/platform/settings/sms', ['enabled' => true, 'account_sid' => ''])->assertSessionDoesntHaveErrors();      // blank means the .env account
        $this->patch('/platform/settings/calls', ['default_caller_id' => 'abc', 'company_caller_ids' => true])->assertSessionHasErrors('default_caller_id');
    }

    public function test_a_company_sends_through_its_own_twilio_account_else_the_platforms(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->fakeHttp(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $acme->run(fn () => $this->assertFalse(app(TwilioClient::class)->available(), 'nothing set up yet'));

        URL::forceRootUrl('https://crm.orvyn-app.com');                 // Twilio is only given a callback address it can reach
        $this->platformAdmin();
        $this->patch('/platform/settings/sms', $this->savedSms());
        PlatformSetting::forgetCache();
        $acme->run(function () {
            $client = app(TwilioClient::class);
            $this->assertSame('platform', $client->account()['source']);
            $this->assertSame('SM1', $client->send('+15552223333', 'Hello'));
        });
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Accounts/ACplatform/Messages.json') && $r['From'] === '+15550001111'
            && str_contains((string) $r['StatusCallback'], '/api/v1/hooks/twilio?token='));        // delivery reports find the company's message

        $acme->run(function () {
            Integration::create(['provider' => 'twilio', 'credentials' => ['account_sid' => 'ACown', 'auth_token' => 'own'], 'settings' => ['from_number' => '+15559998888'], 'is_enabled' => true]);
            $this->assertSame(['company', 'ACown'], [app(TwilioClient::class)->account()['source'], app(TwilioClient::class)->account()['sid']]);
        });
    }

    public function test_a_company_without_any_account_is_told_to_ask_the_platform(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $api = $this->api('owner@acme.test', 'acme');
        $contactId = $api->postJson('/api/v1/contacts', ['first_name' => 'Ann', 'last_name' => 'Lee', 'phone' => '+15551112222'])->assertCreated()->json('data.id');

        $api->postJson("/api/v1/contacts/{$contactId}/sms", ['body' => 'Hi'])->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'platform administrator'));
    }

    public function test_the_test_text_reports_twilios_own_error(): void
    {
        $this->platformAdmin();
        $this->patch('/platform/settings/sms', $this->savedSms());
        PlatformSetting::forgetCache();

        $this->fakeHttp(['api.twilio.com/*' => Http::response(['message' => 'The number is unverified'], 400)]);
        $this->post('/platform/settings/sms/test', ['to' => '+15552223333'])->assertSessionHasErrors(['test' => 'Twilio error: The number is unverified']);

        $this->fakeHttp(['api.twilio.com/*' => Http::response(['sid' => 'SM9'], 201)]);
        $this->post('/platform/settings/sms/test', ['to' => '+15552223333'])->assertSessionHas('success');
    }

    public function test_the_platform_decides_the_default_caller_id_and_whether_companies_set_their_own(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $people = $api()->getJson('/api/v1/users')->json('data');
        $ownerId = collect($people)->firstWhere('email', 'owner@acme.test')['id'];

        $api()->patchJson("/api/v1/users/{$ownerId}", ['caller_id' => '+15551230000'])->assertOk();            // allowed by default
        $api()->getJson('/api/v1/phone')->assertOk()->assertJsonPath('data.can_set', true)->assertJsonPath('data.default_caller_id', null);

        $this->platformAdmin();
        $this->patch('/platform/settings/calls', ['default_caller_id' => '+15557776666', 'company_caller_ids' => false])->assertRedirect()->assertSessionHas('success');
        PlatformSetting::forgetCache();

        $api()->getJson('/api/v1/phone')->assertJsonPath('data.can_set', false)->assertJsonPath('data.default_caller_id', '+15557776666');
        $api()->patchJson("/api/v1/users/{$ownerId}", ['caller_id' => '+15559990000'])->assertUnprocessable()->assertJsonValidationErrors('caller_id');
        $api()->patchJson("/api/v1/users/{$ownerId}", ['name' => 'Renamed Owner'])->assertOk();                  // other edits still work
        $this->assertSame('+15557776666', app(CallSettings::class)->effectiveCallerId(null));
        $this->assertSame('+15551230000', app(CallSettings::class)->effectiveCallerId('+15551230000'));
    }

    public function test_only_platform_administrators_reach_these_settings(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->flushSession();
        $this->defaultHeaders = [];

        $this->get('/platform/settings/sms')->assertRedirect();
        $this->patch('/platform/settings/sms', $this->savedSms())->assertRedirect();
        $this->get('/platform/settings/calls')->assertRedirect();
        $this->assertNull(PlatformSetting::get('sms.account_sid'));

        $this->platformAdmin();
        $this->get('/platform/settings/calls')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/SettingsCalls')->where('calls.company_caller_ids', true));
        $this->get('/platform/settings')->assertOk()->assertInertia(fn ($p) => $p->has('sms.ready')->has('calls.default_caller_id'));
    }

    public function test_a_queued_e_mail_is_sent_from_the_platforms_address_not_the_env_one(): void
    {
        config(['mail.from.address' => 'env@example.test', 'mail.from.name' => 'Env']);
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();
        $this->patch('/platform/settings/mail', ['enabled' => true, 'host' => 'smtp.example.test', 'scheme' => 'smtp', 'from_address' => 'platform@sender.test', 'from_name' => 'Platform'])->assertRedirect();
        PlatformSetting::forgetCache();
        config(['mail.from.address' => 'env@example.test', 'mail.from.name' => 'Env']);                // as a fresh request sees it, before any mailer exists

        $from = $acme->run(function () {
            $contact = Contact::create(['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@x.test']);
            $message = app(MessageComposer::class)->queueEmail($contact, ['subject' => 'Hi', 'html' => '<p>Hi</p>']);

            return [$message->from_email, $message->from_name];
        });

        $this->assertSame(['platform@sender.test', 'Platform'], $from);
    }

    public function test_twilio_is_only_given_a_delivery_callback_it_can_reach(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $callback = fn () => $acme->run(fn () => (new \ReflectionMethod(TwilioClient::class, 'statusCallback'))->invoke(app(TwilioClient::class)));

        config(['app.url' => 'http://127.0.0.1:8000']);
        URL::forceRootUrl('http://127.0.0.1:8000');
        $this->assertNull($callback(), 'a local address would make Twilio refuse the text');

        URL::forceRootUrl('https://crm.orvyn-app.com');
        URL::forceScheme('https');
        $this->assertStringContainsString('https://crm.orvyn-app.com/api/v1/hooks/twilio?token=', (string) $callback());
        URL::forceRootUrl(null);
        URL::forceScheme('http');
    }

    public function test_sendgrid_reports_for_the_shared_account_are_applied_inside_the_company_they_name(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $token = app(MailSettings::class)->sendgridToken();
        $acme->run(fn () => EmailMessage::create(['subject' => 's', 'html' => 'h', 'from_email' => 'a@b.test', 'to' => [['email' => 'ann@x.test']], 'status' => 'sent', 'tracker_id' => 'trk-1']));
        $events = [
            ['event' => 'bounce', 'email' => 'ann@x.test', 'tenant_id' => (string) $acme->id],
            ['event' => 'click', 'email' => 'ann@x.test', 'tenant_id' => (string) $acme->id, 'tracker_id' => 'trk-1'],
            ['event' => 'bounce', 'email' => 'ghost@x.test', 'tenant_id' => 'no-such-company'],
        ];

        $this->postJson('/api/v1/hooks/sendgrid/platform?token=wrong', $events)->assertForbidden();
        $this->postJson("/api/v1/hooks/sendgrid/platform?token={$token}", $events)->assertOk();

        $this->assertTrue($acme->run(fn () => EmailSuppression::where('email', 'ann@x.test')->exists()));
        $this->assertSame(1, $acme->run(fn () => (int) EmailMessage::where('tracker_id', 'trk-1')->value('click_count')));
        $this->assertFalse($beta->run(fn () => EmailSuppression::where('email', 'ann@x.test')->exists()), 'another company is untouched');
    }

    public function test_sendgrid_reports_can_be_required_to_be_signed_and_the_address_can_be_regenerated(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();
        $mail = app(MailSettings::class);

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $public = trim(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"], '', openssl_pkey_get_details($key)['key']));
        $body = json_encode([['event' => 'bounce', 'email' => 'sig@x.test', 'tenant_id' => (string) $acme->id]]);
        $timestamp = (string) time();
        openssl_sign($timestamp.$body, $signature, $key, OPENSSL_ALGO_SHA256);

        $this->get('/platform/settings/mail')->assertOk()->assertInertia(fn ($p) => $p->where('sendgrid.key_set', false)->has('sendgrid.url'));
        $this->patch('/platform/settings/mail/sendgrid', ['verification_key' => 'not a key!!'])->assertSessionHasErrors('verification_key');
        $this->patch('/platform/settings/mail/sendgrid', ['verification_key' => $public])->assertRedirect()->assertSessionHas('success');
        PlatformSetting::forgetCache();
        $this->assertTrue($mail->sendgridVerificationKeySet());

        $url = '/api/v1/hooks/sendgrid/platform?token='.$mail->sendgridToken();
        $sign = ['X-Twilio-Email-Event-Webhook-Signature' => base64_encode($signature), 'X-Twilio-Email-Event-Webhook-Timestamp' => $timestamp];
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertForbidden();                    // unsigned
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => $sign['X-Twilio-Email-Event-Webhook-Signature'], 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $timestamp], $body.' ')->assertForbidden();   // altered
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => $sign['X-Twilio-Email-Event-Webhook-Signature'], 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $timestamp], $body)->assertOk();
        $this->assertTrue($acme->run(fn () => EmailSuppression::where('email', 'sig@x.test')->exists()));

        $old = $mail->sendgridToken();
        $this->post('/platform/settings/mail/sendgrid/rotate')->assertRedirect()->assertSessionHas('success');
        PlatformSetting::forgetCache();
        $this->assertNotSame($old, $mail->sendgridToken());
        $this->postJson('/api/v1/hooks/sendgrid/platform?token='.$old, [])->assertForbidden();                          // the old address is dead

        $this->patch('/platform/settings/mail/sendgrid', ['verification_key' => ''])->assertRedirect();                  // removing the key goes back to the secret alone
        PlatformSetting::forgetCache();
        $this->assertFalse($mail->sendgridVerificationKeySet());
    }

    /** A fake Twilio that owns one number, has one verified caller ID, and verifies whatever it is asked to. */
    private function fakeTwilioNumbers(): void
    {
        $this->fakeHttp(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'IncomingPhoneNumbers')) {
                return Http::response(['incoming_phone_numbers' => [['sid' => 'PN1', 'phone_number' => '+15550001111', 'friendly_name' => 'Main line']]]);
            }
            if ($request->method() === 'POST' && str_ends_with(strtok($url, '?'), 'OutgoingCallerIds.json')) {
                return Http::response(['validation_code' => '123456', 'phone_number' => $request['PhoneNumber']]);
            }
            if (str_contains($url, 'OutgoingCallerIds')) {
                return Http::response(['outgoing_caller_ids' => [['sid' => 'PNv1', 'phone_number' => '+15550002222', 'friendly_name' => 'Sales']]]);
            }

            return Http::response([], 200);
        });
    }

    public function test_a_company_on_the_platform_account_only_sees_the_numbers_the_platform_offers(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->fakeTwilioNumbers();
        $this->platformAdmin();
        $this->patch('/platform/settings/sms', $this->savedSms());
        PlatformSetting::forgetCache();
        $ownerId = fn () => collect($this->api('owner@acme.test', 'acme')->getJson('/api/v1/users')->json('data'))->firstWhere('email', 'owner@acme.test')['id'];
        $id = $ownerId();

        $this->platformAdmin();
        $this->get('/platform/settings/calls')->assertOk()->assertInertia(fn ($p) => $p->where('account_ready', true)->has('numbers', 2)->where('numbers.0.number', '+15550001111'));
        $this->patch('/platform/settings/calls/offered', ['numbers' => ['+15550002222', '+19990000000']])->assertRedirect()->assertSessionHas('success');
        PlatformSetting::forgetCache();
        $this->assertSame(['+15550002222'], app(CallSettings::class)->offeredNumbers());           // a number the account does not have is dropped

        $api = fn () => $this->api('owner@acme.test', 'acme');
        $api()->getJson('/api/v1/caller-ids')->assertOk()->assertJsonPath('data.source', 'platform')->assertJsonPath('data.can_manage', false)
            ->assertJsonCount(1, 'data.numbers')->assertJsonPath('data.numbers.0.number', '+15550002222');
        $api()->patchJson("/api/v1/users/{$id}", ['caller_id' => '+15550001111'])->assertUnprocessable()->assertJsonValidationErrors('caller_id');   // owned, but not offered
        $api()->patchJson("/api/v1/users/{$id}", ['caller_id' => '+15550002222'])->assertOk();
        $api()->postJson('/api/v1/caller-ids/verify', ['number' => '+15553334444'])->assertForbidden();                          // the shared account is not theirs to change
        $api()->deleteJson('/api/v1/caller-ids/PNv1')->assertForbidden();
    }

    public function test_the_platform_verifies_a_number_by_a_call_and_waits_for_twilio(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->fakeTwilioNumbers();
        $this->platformAdmin();
        $this->patch('/platform/settings/sms', $this->savedSms());
        PlatformSetting::forgetCache();

        $this->post('/platform/settings/calls/numbers', ['number' => 'not a number'])->assertSessionHasErrors('number');
        $this->post('/platform/settings/calls/numbers', ['number' => '+1 (555) 333-4444', 'label' => 'Support'])->assertRedirect()->assertSessionHas('caller_verification.code', '123456');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['PhoneNumber'] ?? null) === '+15553334444' && ($r['FriendlyName'] ?? null) === 'Support');

        $this->getJson('/platform/settings/calls/numbers/status?number=%2B15550002222')->assertOk()->assertJson(['verified' => true]);
        $this->getJson('/platform/settings/calls/numbers/status?number=%2B15553334444')->assertOk()->assertJson(['verified' => false]);
    }

    public function test_a_company_with_its_own_twilio_account_manages_its_numbers_and_may_only_use_those(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->fakeTwilioNumbers();
        $acme->run(fn () => Integration::create(['provider' => 'twilio', 'credentials' => ['account_sid' => 'ACown', 'auth_token' => 'own'], 'settings' => [], 'is_enabled' => true]));
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $id = collect($api()->getJson('/api/v1/users')->json('data'))->firstWhere('email', 'owner@acme.test')['id'];

        $api()->getJson('/api/v1/caller-ids')->assertOk()->assertJsonPath('data.source', 'company')->assertJsonPath('data.can_manage', true)->assertJsonCount(2, 'data.numbers');
        $api()->patchJson("/api/v1/users/{$id}", ['caller_id' => '+1 555 000 1111'])->assertOk();                  // typed with spaces: still the owned number
        $api()->patchJson("/api/v1/users/{$id}", ['caller_id' => '+15558887777'])->assertUnprocessable()->assertJsonValidationErrors('caller_id');

        $api()->postJson('/api/v1/caller-ids/verify', ['number' => '12345'])->assertUnprocessable();
        $api()->postJson('/api/v1/caller-ids/verify', ['number' => '+15553334444', 'label' => 'Support'])->assertOk()->assertJsonPath('data.code', '123456')->assertJsonPath('data.number', '+15553334444');
        $api()->getJson('/api/v1/caller-ids/status?number=%2B15550002222')->assertOk()->assertJsonPath('data.verified', true);
        $api()->getJson('/api/v1/caller-ids/status?number=%2B15553334444')->assertOk()->assertJsonPath('data.verified', false);
        $api()->patchJson('/api/v1/caller-ids/PNv1', ['label' => 'Sales team'])->assertOk();
        $api()->deleteJson('/api/v1/caller-ids/PNv1')->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/Accounts/ACown/OutgoingCallerIds/PNv1.json'));
    }

    public function test_a_company_saves_its_own_twilio_account_and_it_wins_over_the_platforms(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();
        $this->patch('/platform/settings/sms', $this->savedSms());
        PlatformSetting::forgetCache();
        $api = fn () => $this->api('owner@acme.test', 'acme');

        $api()->getJson('/api/v1/integrations/twilio')->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.platform_available', true);
        $api()->putJson('/api/v1/integrations/twilio', ['account_sid' => 'nope', 'auth_token' => 'secret-token-1234'])->assertUnprocessable()->assertJsonValidationErrors('account_sid');
        $api()->putJson('/api/v1/integrations/twilio', ['account_sid' => 'ACabcdef0123456789', 'from_number' => '+15559998888'])->assertUnprocessable()->assertJsonValidationErrors('auth_token');

        $api()->putJson('/api/v1/integrations/twilio', ['account_sid' => 'ACabcdef0123456789', 'auth_token' => 'secret-token-1234', 'from_number' => '+15559998888'])
            ->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.token_hint', '••••1234')->assertJsonMissingPath('data.auth_token');
        $acme->run(fn () => $this->assertSame(['company', 'ACabcdef0123456789'], [app(TwilioClient::class)->account()['source'], app(TwilioClient::class)->account()['sid']]));

        $api()->putJson('/api/v1/integrations/twilio', ['account_sid' => 'ACabcdef0123456789', 'auth_token' => '', 'from_number' => '+15550000000'])->assertOk();      // an empty token keeps the saved one
        $acme->run(fn () => $this->assertSame('secret-token-1234', Integration::for('twilio')->credentials['auth_token']));

        $this->fakeHttp(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);
        $api()->postJson('/api/v1/integrations/twilio/test')->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Authenticate'));
        $this->fakeHttp(['api.twilio.com/*' => Http::response(['sid' => 'ACabcdef0123456789'], 200)]);
        $api()->postJson('/api/v1/integrations/twilio/test')->assertOk()->assertJsonPath('ok', true);

        $api()->deleteJson('/api/v1/integrations/twilio')->assertOk()->assertJsonPath('data.configured', false);
        $acme->run(fn () => $this->assertSame('platform', app(TwilioClient::class)->account()['source']));              // back to the platform's account
    }

    public function test_the_automation_editor_is_told_when_e_mails_or_texts_cannot_be_sent(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $api = fn () => $this->api('owner@acme.test', 'acme');

        $none = $api()->getJson('/api/v1/messages/readiness')->assertOk()->json('data');                     // no mail server (the log mailer), no Twilio
        $this->assertFalse($none['email']['ready']);
        $this->assertStringContainsString('No e-mail server', $none['email']['reason']);
        $this->assertFalse($none['sms']['ready']);
        $this->assertStringContainsString('No Twilio account', $none['sms']['reason']);

        $this->platformAdmin();
        app(MailSettings::class)->save(['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'scheme' => 'smtp']);
        $this->patch('/platform/settings/sms', $this->savedSms());
        PlatformSetting::forgetCache();
        $api()->getJson('/api/v1/messages/readiness')->assertOk()->assertJsonPath('data.email.ready', true)->assertJsonPath('data.sms.ready', true)->assertJsonPath('data.sms.reason', null);

        app(CompanyServices::class)->set($acme, 'messages', false);                                          // the platform paused sending for this company
        $paused = $api()->getJson('/api/v1/messages/readiness')->json('data');
        $this->assertFalse($paused['email']['ready']);
        $this->assertFalse($paused['sms']['ready']);
        $this->assertStringContainsString('paused', $paused['email']['reason']);
    }
}
