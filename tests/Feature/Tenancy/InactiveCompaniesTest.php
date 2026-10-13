<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\CompanyActivity;
use App\Services\Tenancy\InactiveCompanies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** Companies nobody uses: flagged after the chosen number of days, announced once, and decided on by a platform administrator. */
class InactiveCompaniesTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
    }

    private function age(Tenant $tenant, int $days): void
    {
        $tenant->update(['last_active_at' => now()->subDays($days), 'inactive_since' => null]);
    }

    private function platformAdmin(string $email = 'platform@x.test'): AccountUser
    {
        $admin = AccountUser::firstOrCreate(['email' => $email], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => $email, 'password' => 'Platform-Passw0rd-1']);

        return $admin;
    }

    /** @return list<string> every address an e-mail was sent to so far */
    private function recipients(): array
    {
        return collect(Mail::mailer()->getSymfonyTransport()->messages()->all())
            ->flatMap(fn ($sent) => collect($sent->getOriginalMessage()->getTo())->map->getAddress())->values()->all();
    }

    private function feedFor(Tenant $tenant): int
    {
        return $tenant->run(fn () => DB::table('notifications_feed')->count());
    }

    public function test_the_scan_flags_only_the_companies_idle_for_longer_than_the_chosen_days_and_only_once(): void
    {
        $quiet = $this->company('Quiet', 'owner@quiet.test');
        $busy = $this->company('Busy', 'owner@busy.test');
        $this->age($quiet, 45);
        $this->age($busy, 5);

        $first = app(InactiveCompanies::class)->scan();
        $second = app(InactiveCompanies::class)->scan();

        $this->assertSame(1, $first['flagged']);
        $this->assertSame(0, $second['flagged']);
        $this->assertNotNull($quiet->fresh()->inactive_since);
        $this->assertNull($busy->fresh()->inactive_since);
        $this->assertSame(1, $this->feedFor($quiet));
        $this->assertSame(0, $this->feedFor($busy), 'a company in use is not told anything');
    }

    public function test_the_number_of_days_comes_from_the_platform_settings(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        $this->age($company, 20);
        $inactive = app(InactiveCompanies::class);

        $this->assertSame(0, $inactive->scan()['flagged'], 'default is 30 days');

        $inactive->save(['enabled' => true, 'days' => 14, 'notify_admins' => true]);
        PlatformSetting::forgetCache();

        $this->assertSame(1, $inactive->scan()['flagged']);
    }

    public function test_a_switched_off_check_flags_nothing(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        $this->age($company, 90);
        app(InactiveCompanies::class)->save(['enabled' => false, 'days' => 30, 'notify_admins' => true]);
        PlatformSetting::forgetCache();

        $this->assertSame(['flagged' => 0, 'cleared' => 0], app(InactiveCompanies::class)->scan());
        $this->assertNull($company->fresh()->inactive_since);
    }

    public function test_a_company_never_used_counts_from_the_day_it_was_created(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        $company->forceFill(['last_active_at' => null, 'created_at' => now()->subDays(50)])->save();

        $this->assertSame(1, app(InactiveCompanies::class)->scan()['flagged']);
    }

    public function test_the_company_administrators_and_the_platform_administrators_are_told(): void
    {
        $company = $this->company('Quiet', 'owner@quiet.test');
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true, 'notify_inactive_companies' => true]);
        AccountUser::create(['name' => 'Muted Admin', 'email' => 'muted@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true, 'notify_inactive_companies' => false]);
        $this->age($company, 45);

        app(InactiveCompanies::class)->scan();

        $to = $this->recipients();
        $this->assertContains('owner@quiet.test', $to);
        $this->assertContains('platform@x.test', $to);
        $this->assertNotContains('muted@x.test', $to, 'an administrator who switched this topic off is not e-mailed');
    }

    public function test_company_administrators_are_not_told_when_the_platform_turned_that_off_but_the_platform_still_is(): void
    {
        $company = $this->company('Quiet', 'owner@quiet.test');
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        app(InactiveCompanies::class)->save(['enabled' => true, 'days' => 30, 'notify_admins' => false]);
        PlatformSetting::forgetCache();
        $this->age($company, 45);

        app(InactiveCompanies::class)->scan();

        $this->assertSame(0, $this->feedFor($company));
        $this->assertNotContains('owner@quiet.test', $this->recipients());
        $this->assertContains('platform@x.test', $this->recipients());
    }

    public function test_a_company_used_again_loses_its_flag_at_the_next_scan(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        $this->age($company, 45);
        app(InactiveCompanies::class)->scan();
        $company->update(['last_active_at' => now()]);

        $result = app(InactiveCompanies::class)->scan();

        $this->assertSame(1, $result['cleared']);
        $this->assertNull($company->fresh()->inactive_since);
    }

    public function test_flagging_one_company_does_not_touch_another(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $this->age($acme, 60);
        $this->age($beta, 60);
        $beta->update(['status' => 'suspended']);

        app(InactiveCompanies::class)->scan();

        $this->assertNotNull($acme->fresh()->inactive_since);
        $this->assertNull($beta->fresh()->inactive_since, 'a company that is already suspended is not flagged');
    }

    public function test_use_of_the_app_marks_the_company_active_and_clears_the_flag(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        $this->age($company, 45);
        app(InactiveCompanies::class)->scan();
        Cache::forget("company-active:{$company->getTenantKey()}");

        app(CompanyActivity::class)->touch($company);

        $company->refresh();
        $this->assertNull($company->inactive_since);
        $this->assertTrue($company->last_active_at->isToday());
    }

    public function test_activity_is_recorded_at_most_once_in_ten_minutes(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        Cache::forget("company-active:{$company->getTenantKey()}");
        $this->age($company, 45);

        app(CompanyActivity::class)->touch($company);
        $this->age($company, 45);
        app(CompanyActivity::class)->touch($company);

        $this->assertTrue($company->fresh()->last_active_at->lt(now()->subDays(40)), 'the second touch within ten minutes is skipped');
    }

    public function test_the_platform_can_keep_suspend_or_close_a_flagged_company(): void
    {
        $kept = $this->company('Kept', 'owner@kept.test');
        $suspended = $this->company('Suspended', 'owner@suspended.test');
        $closed = $this->company('Closed', 'owner@closed.test');
        foreach ([$kept, $suspended, $closed] as $c) {
            $this->age($c, 45);
        }
        app(InactiveCompanies::class)->scan();
        $this->platformAdmin();

        $this->post("/platform/companies/{$kept->getTenantKey()}/inactive/keep")->assertRedirect();
        $this->post("/platform/companies/{$suspended->getTenantKey()}/inactive/suspend", ['note' => 'Unused for a month'])->assertRedirect();
        $this->post("/platform/companies/{$closed->getTenantKey()}/inactive/close", ['note' => 'Abandoned', 'tell' => false])->assertRedirect();

        $kept->refresh();
        $this->assertSame('active', $kept->status);
        $this->assertNull($kept->inactive_since);
        $this->assertTrue($kept->last_active_at->isToday());

        $suspended->refresh();
        $this->assertSame('suspended', $suspended->status);
        $this->assertSame('Unused for a month', $suspended->status_note);
        $this->assertNull($suspended->inactive_since);

        $closed->refresh();
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertFalse($closed->isActive());
    }

    public function test_the_company_administrators_are_e_mailed_about_a_suspension_unless_the_platform_unticks_it(): void
    {
        $told = $this->company('Told', 'owner@told.test');
        $silent = $this->company('Silent', 'owner@silent.test');
        $this->platformAdmin();

        $this->post("/platform/companies/{$told->getTenantKey()}/inactive/suspend")->assertRedirect();
        $this->post("/platform/companies/{$silent->getTenantKey()}/inactive/suspend", ['tell' => false])->assertRedirect();

        $this->assertContains('owner@told.test', $this->recipients());
        $this->assertNotContains('owner@silent.test', $this->recipients());
    }

    public function test_a_company_that_is_already_suspended_cannot_be_decided_on_again(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        $company->update(['status' => 'suspended']);
        $this->platformAdmin();

        $this->post("/platform/companies/{$company->getTenantKey()}/inactive/close")->assertStatus(422);
    }

    public function test_activating_a_company_starts_the_clock_again(): void
    {
        $company = $this->company('Acme', 'owner@acme.test');
        $company->update(['status' => 'closed', 'status_note' => 'Abandoned', 'closed_at' => now(), 'last_active_at' => now()->subDays(90)]);
        $this->platformAdmin();

        $this->patch("/platform/companies/{$company->getTenantKey()}", ['status' => 'active'])->assertRedirect();

        $company->refresh();
        $this->assertSame('active', $company->status);
        $this->assertNull($company->status_note);
        $this->assertNull($company->closed_at);
        $this->assertTrue($company->last_active_at->isToday());
    }

    public function test_the_settings_are_validated_and_only_for_platform_administrators(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->patch('/platform/settings/inactive-companies', ['enabled' => true, 'days' => 30, 'notify_admins' => true])->assertRedirect('/login');

        $this->platformAdmin();
        $this->patch('/platform/settings/inactive-companies', ['enabled' => true, 'days' => 3, 'notify_admins' => true])->assertSessionHasErrors('days');
        $this->patch('/platform/settings/inactive-companies', ['enabled' => true, 'days' => 400, 'notify_admins' => true])->assertSessionHasErrors('days');
        $this->patch('/platform/settings/inactive-companies', ['enabled' => true, 'days' => 60, 'notify_admins' => false])->assertSessionHasNoErrors();

        PlatformSetting::forgetCache();
        $this->assertSame(60, app(InactiveCompanies::class)->days());
        $this->assertFalse(app(InactiveCompanies::class)->tellsCompanyAdmins());
    }

    public function test_the_companies_page_lists_the_idle_days_and_which_are_flagged(): void
    {
        $quiet = $this->company('Quiet', 'owner@quiet.test');
        $this->age($quiet, 45);
        app(InactiveCompanies::class)->scan();
        $this->platformAdmin();

        $this->get('/platform')->assertInertia(fn ($page) => $page
            ->where('inactivity.days', 30)
            ->where('companies.0.inactive', true)
            ->where('companies.0.idle_days', 45));
    }
}
