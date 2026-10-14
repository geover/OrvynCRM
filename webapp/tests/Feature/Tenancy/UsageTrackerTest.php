<?php

namespace Tests\Feature\Tenancy;

use App\Integrations\Twilio\TwilioClient;
use App\Jobs\SendSmsMessage;
use App\Models\AccountUser;
use App\Models\CompanyUsage;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\SmsNumbers;
use App\Services\Tenancy\TenantProvisioner;
use App\Services\Tenancy\UsageTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** Usage against the tier: a record per month, warnings at 80 and 100 percent, the text limit, and numbers trimmed on a smaller tier. */
class UsageTrackerTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
        config(['services.twilio.account_sid' => 'ACenv0000000000', 'services.twilio.auth_token' => 'envtoken', 'services.twilio.from_number' => '+15550001111']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'])]);
    }

    private function texts(Tenant $tenant, int $n, string $status = 'sent'): void
    {
        $tenant->run(fn () => collect(range(1, $n))->each(fn ($i) => SmsMessage::create(['to_number' => '+1777'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'body' => 'x', 'status' => $status, 'sent_at' => $status === 'sent' ? now() : null])));
    }

    private function bell(Tenant $tenant): int
    {
        return $tenant->run(fn () => DB::table('notifications_feed')->count());
    }

    private function send(Tenant $tenant, string $to): SmsMessage
    {
        return $tenant->run(function () use ($to) {
            $m = SmsMessage::create(['to_number' => $to, 'body' => 'hello', 'status' => 'queued']);
            (new SendSmsMessage($m->id))->handle(app(TwilioClient::class), app(CompanyServices::class), app(Plans::class));

            return $m->fresh();
        });
    }

    public function test_a_record_is_made_for_the_month_with_the_usage_and_the_limits_of_the_tier(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        app(Plans::class)->applyTier($tenant, 1);                  // a company made by the helper has the default limit, not a tier's
        $tenant->refresh();
        $this->texts($tenant, 12);

        $row = app(UsageTracker::class)->refresh($tenant);

        $this->assertSame([1, 1, 5, 12, 100, 1], [$row->tier, $row->users, $row->users_limit, $row->sms, $row->sms_limit, $row->numbers_limit]);
        $this->assertSame(1, CompanyUsage::count());
        $this->assertSame('ok', $row->level());
    }

    public function test_the_company_is_warned_once_at_80_percent_and_once_at_100(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $tracker = app(UsageTracker::class);

        $this->texts($tenant, 80);
        $reached = [];
        $tracker->refresh($tenant, $reached);
        $this->assertSame(1, $this->bell($tenant));
        $this->assertSame([], $reached);
        $tracker->refresh($tenant);
        $this->assertSame(1, $this->bell($tenant), 'the same stage is told once');

        $this->texts($tenant, 20);
        $tracker->refresh($tenant, $reached);
        $this->assertSame(2, $this->bell($tenant));
        $this->assertCount(1, $reached);
        $this->assertSame(100, CompanyUsage::first()->sms_alert);
        $this->assertSame('at', CompanyUsage::first()->level());
    }

    public function test_the_hourly_run_tells_the_platform_once_about_companies_that_reached_a_limit(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->texts($tenant, 100);
        $before = count(Mail::mailer()->getSymfonyTransport()->messages()->all());

        app(UsageTracker::class)->run();
        $afterFirst = count(Mail::mailer()->getSymfonyTransport()->messages()->all());
        app(UsageTracker::class)->run();

        $this->assertGreaterThan($before, $afterFirst);
        $this->assertSame($afterFirst, count(Mail::mailer()->getSymfonyTransport()->messages()->all()), 'no second digest');
    }

    public function test_the_hundredth_text_of_the_month_is_sent_and_the_next_one_is_refused(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->texts($tenant, 99);

        $this->assertSame('sent', $this->send($tenant, '+15558880100')->status);
        $refused = $this->send($tenant, '+15558880101');

        $this->assertSame('failed', $refused->status);
        $this->assertStringContainsString('limit', (string) $refused->error);
        $this->assertSame(100, CompanyUsage::first()->sms_alert, 'running out of texts warns the company at once');
    }

    public function test_a_smaller_tier_keeps_only_the_texting_numbers_it_allows_and_the_default_stays(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $plans->applyTier($tenant, 3);
        $tenant->run(fn () => Setting::put('sms.from_numbers', ['+15550000001', '+15550000002', '+15550000003']));

        $plans->applyTier($tenant, 1);

        $this->assertSame(['+15550000001'], $tenant->run(fn () => app(SmsNumbers::class)->chosen()));
        $this->assertSame(1, $plans->numbersUsed($tenant));
    }

    public function test_the_history_lists_the_months_newest_first(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        foreach (['2026-08', '2026-09', '2026-10'] as $p) {
            CompanyUsage::create(['tenant_id' => $tenant->getTenantKey(), 'period' => $p, 'tier' => 1, 'users' => 2, 'users_limit' => 5, 'sms' => 10, 'sms_limit' => 100, 'numbers' => 1, 'numbers_limit' => 1]);
        }

        $this->assertSame(['2026-10', '2026-09', '2026-08'], app(UsageTracker::class)->history($tenant)->pluck('period')->all());
    }

    public function test_the_platform_sees_every_company_against_its_tier_and_the_company_sees_its_history(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->texts($acme, 85);
        app(UsageTracker::class)->refresh($acme);
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);

        $this->defaultHeaders = [];

        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
        $this->get('/platform/usage')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Usage')->where('companies.0.level', 'near')->where('companies.0.sms.used', 85));
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('companies.0.usage_level', 'near'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->bearerFor('owner@acme.test', 'Owner-Passw0rd-1');
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.history.0.sms', 85)->assertJsonPath('data.numbers.max', 1);
    }

    public function test_only_platform_administrators_open_the_usage_page(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->get('/platform/usage')->assertRedirect('/login');
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->bearerFor('owner@acme.test', 'Owner-Passw0rd-1');
        $this->get('/platform/usage')->assertForbidden();
    }

    public function test_a_company_added_after_installing_gets_its_record_when_the_page_is_opened(): void
    {
        app(TenantProvisioner::class)->createCompany('Late Co', 'late-co', ['name' => 'Lee', 'email' => 'lee@late.test', 'password' => 'Late-Passw0rd-1']);
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->get('/platform/usage')->assertOk()->assertInertia(fn ($p) => $p->has('companies', 1));
        $this->assertSame(1, CompanyUsage::count());
    }
}
