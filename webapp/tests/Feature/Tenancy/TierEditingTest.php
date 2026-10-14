<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\SmsNumbers;
use App\Services\Tenancy\Subscriptions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Tests\TenantTestCase;

/** What a licence tier includes can be edited on the platform; companies follow at once, and one company can be given extras on top. */
class TierEditingTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
    }

    /** @return array<int, array{name: string, users: int|null, sms_per_month: int, sms_numbers: int}> */
    private function tiers(array $override = []): array
    {
        $base = [
            1 => ['name' => 'Tier 1', 'users' => 5, 'sms_per_month' => 100, 'sms_numbers' => 1],
            2 => ['name' => 'Tier 2', 'users' => 15, 'sms_per_month' => 500, 'sms_numbers' => 3],
            3 => ['name' => 'Tier 3', 'users' => 50, 'sms_per_month' => 1000, 'sms_numbers' => 5],
            4 => ['name' => 'Tier 4', 'users' => 100, 'sms_per_month' => 3000, 'sms_numbers' => 10],
            5 => ['name' => 'Tier 5', 'users' => null, 'sms_per_month' => 5000, 'sms_numbers' => 20],
        ];

        return array_replace_recursive($base, $override);
    }

    private function platformAdmin(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    /** @return array<string, mixed> */
    private function form(array $tiers, array $over = []): array
    {
        return $over + ['trial_months' => 3, 'prices' => [1 => null, 2 => null, 3 => null, 4 => null, 5 => null], 'tiers' => $tiers];
    }

    public function test_the_shipped_tiers_are_used_until_they_are_edited(): void
    {
        $tiers = app(Plans::class)->tiers();

        $this->assertSame([5, 15, 50, 100, null], array_column($tiers, 'users'));
        $this->assertFalse(collect($tiers)->contains('customised', true));
    }

    public function test_saved_tiers_replace_the_shipped_ones_and_can_be_reset(): void
    {
        $plans = app(Plans::class);
        $plans->saveTiers($this->tiers([2 => ['name' => 'Growth', 'users' => 20, 'sms_per_month' => 600, 'sms_numbers' => 4]]));
        PlatformSetting::forgetCache();

        $this->assertSame(['Growth', 20, 600, 4, true], [$plans->tier(2)['name'], $plans->tier(2)['users'], $plans->tier(2)['sms_per_month'], $plans->tier(2)['sms_numbers'], $plans->tier(2)['customised']]);
        $this->assertSame(20, $plans->seatsFor(2));

        $plans->resetTiers();
        PlatformSetting::forgetCache();
        $this->assertSame(['Tier 2', 15], [$plans->tier(2)['name'], $plans->tier(2)['users']]);
    }

    public function test_companies_follow_a_changed_tier_and_one_with_extra_users_keeps_its_extra(): void
    {
        $plain = $this->company('Plain', 'owner@plain.test');
        $extra = $this->company('Extra', 'owner@extra.test');
        $plans = app(Plans::class);
        $plans->applyTier($plain, 2);
        $plans->applyTier($extra, 2);
        $extra->update(['max_users' => 25]);                                     // 10 extra users on top of Tier 2's 15

        $plans->saveTiers($this->tiers([2 => ['users' => 20]]));

        $this->assertSame(20, (int) $plain->fresh()->max_users);
        $this->assertSame(30, (int) $extra->fresh()->max_users);
        $plans->resetTiers();
        $this->assertSame([15, 25], [(int) $plain->fresh()->max_users, (int) $extra->fresh()->max_users]);
    }

    public function test_a_company_on_the_unlimited_tier_follows_when_the_top_tier_gets_a_number_and_back(): void
    {
        $big = $this->company('Big', 'owner@big.test');
        $plans = app(Plans::class);
        $plans->applyTier($big, 5);
        $this->assertTrue(Plans::isUnlimitedSeats((int) $big->fresh()->max_users));

        $plans->saveTiers($this->tiers([5 => ['users' => 500]]));
        $this->assertSame(500, (int) $big->fresh()->max_users);

        $plans->saveTiers($this->tiers());
        $this->assertTrue(Plans::isUnlimitedSeats((int) $big->fresh()->max_users));
    }

    public function test_texts_and_texting_numbers_follow_live_and_numbers_beyond_the_allowance_are_dropped(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $plans->applyTier($acme, 3);                                              // 5 numbers
        $acme->run(fn () => Setting::put('sms.from_numbers', ['+15550000001', '+15550000002', '+15550000003', '+15550000004']));
        $this->assertSame(1000, $plans->smsLimit($acme->fresh()));

        $result = $plans->saveTiers($this->tiers([3 => ['sms_per_month' => 1500, 'sms_numbers' => 2]]));

        $this->assertSame(1500, $plans->smsLimit($acme->fresh()));
        $this->assertSame(['+15550000001', '+15550000002'], $acme->run(fn () => app(SmsNumbers::class)->chosen()));
        $this->assertSame(2, $result['trimmed']);
    }

    public function test_extras_raise_the_companys_own_limits_and_lowering_them_trims_numbers(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $acme->update(['extra_sms' => 250, 'extra_numbers' => 2]);               // Tier 1: 100 texts and 1 number, plus the extras

        $this->assertSame([350, 3], [$plans->smsLimit($acme->fresh()), $plans->smsNumbersMax($acme->fresh())]);
        $acme->run(fn () => Setting::put('sms.from_numbers', ['+15550000001', '+15550000002', '+15550000003']));
        $this->assertSame(3, $plans->numbersUsed($acme->fresh()));

        $acme->update(['extra_numbers' => 0]);
        $plans->enforceNumbers($acme->fresh());
        $this->assertSame(['+15550000001'], $acme->run(fn () => app(SmsNumbers::class)->chosen()));
        $this->assertSame(['sms' => 250, 'bonus_sms' => 0, 'numbers' => 0], $plans->summary($acme->fresh())['extras']);
    }

    public function test_the_preview_names_the_companies_that_would_be_above_a_limit_without_changing_anything(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $plans->applyTier($acme, 2);
        $acme->run(fn () => Setting::put('sms.from_numbers', ['+15550000001', '+15550000002', '+15550000003']));

        $impact = $plans->impact($this->tiers([2 => ['sms_numbers' => 1]]));

        $this->assertSame(1, $impact['companies'][2]);
        $this->assertSame(['Acme'], array_column($impact['over'], 'company'));
        $this->assertStringContainsString('texting numbers', $impact['over'][0]['what']);
        $this->assertSame(3, $plans->tier(2)['sms_numbers'], 'nothing was saved');
    }

    public function test_the_platform_saves_tiers_from_the_page_and_the_names_show_everywhere(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        app(Plans::class)->applyTier($acme, 2);
        $this->platformAdmin();

        $this->patch('/platform/settings/plans', $this->form($this->tiers([2 => ['name' => 'Growth', 'users' => 20]])))->assertRedirect()->assertSessionHas('success');

        PlatformSetting::forgetCache();
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('tiers.1.name', 'Growth')->where('tiers.1.users', 20));
        $this->assertSame(20, (int) $acme->fresh()->max_users);
        $this->get('/platform/settings/plans?tiers[2][name]=Growth&tiers[2][users]=10&tiers[2][sms_per_month]=500&tiers[2][sms_numbers]=3', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'Platform/SettingsPlans', 'X-Inertia-Partial-Data' => 'impact'])
            ->assertOk()->assertJsonStructure(['props' => ['impact' => ['over']]]);
    }

    public function test_a_bigger_tier_cannot_include_less_and_only_the_top_tier_can_be_unlimited(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/settings/plans', $this->form($this->tiers([3 => ['users' => 10]])))->assertSessionHasErrors('tiers.3.users');
        $this->patch('/platform/settings/plans', $this->form($this->tiers([2 => ['sms_per_month' => 50]])))->assertSessionHasErrors('tiers.2.sms_per_month');
        $this->patch('/platform/settings/plans', $this->form($this->tiers([4 => ['sms_numbers' => 2]])))->assertSessionHasErrors('tiers.4.sms_numbers');
        $this->patch('/platform/settings/plans', $this->form($this->tiers([2 => ['users' => null]])))->assertSessionHasErrors('tiers.2.users');
        $this->patch('/platform/settings/plans', $this->form($this->tiers([2 => ['name' => 'tier 3']])))->assertSessionHasErrors('tiers.3.name');      // the later of the two is the one flagged
        $this->assertSame(15, app(Plans::class)->tier(2)['users'], 'nothing was saved');
    }

    public function test_only_platform_administrators_edit_the_tiers(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->patch('/platform/settings/plans', $this->form($this->tiers()))->assertRedirect('/login');
        $this->post('/platform/settings/plans/reset')->assertRedirect('/login');

        $this->defaultHeaders = [];

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->bearerFor('owner@acme.test', 'Owner-Passw0rd-1');
        $this->patch('/platform/settings/plans', $this->form($this->tiers()))->assertForbidden();
    }

    public function test_a_platform_administrator_gives_a_company_extra_texts_and_numbers_on_edit(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();
        $url = "/platform/companies/{$acme->getTenantKey()}";

        $this->patch($url, ['extra_sms' => 300, 'extra_numbers' => 1])->assertSessionHasNoErrors();
        $this->assertSame([300, 1], [(int) $acme->fresh()->extra_sms, (int) $acme->fresh()->extra_numbers]);
        $this->patch($url, ['extra_sms' => -1])->assertSessionHasErrors('extra_sms');
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('companies.0.extra_sms', 300));
        $this->get("{$url}")->assertOk()->assertInertia(fn ($p) => $p->where('plan.sms.max', 400)->where('plan.extras.sms', 300));
    }

    public function test_the_company_billing_page_shows_the_new_names_and_the_limits_with_extras(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['extra_sms' => 50]);
        app(Plans::class)->saveTiers($this->tiers([2 => ['name' => 'Growth']]));
        PlatformSetting::forgetCache();
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->bearerFor('owner@acme.test', 'Owner-Passw0rd-1');

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.tiers.1.name', 'Growth')->assertJsonPath('data.sms.max', 150)->assertJsonPath('data.extras.sms', 50);
    }

    public function test_a_renamed_tier_renames_its_product_on_stripe(): void
    {
        app(PaymentSettings::class)->save(['enabled' => true, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => 'whsec_x']);
        $plans = app(Plans::class);
        $plans->saveSettings(['trial_months' => 3, 'prices' => [1 => 30, 2 => 79]]);
        app(PaymentSettings::class)->savePrice(2, 'prod_2', 'price_2', '7900|USD|month');
        app(PaymentSettings::class)->saveProductName(2, 'Tier 2');
        $plans->saveTiers($this->tiers([2 => ['name' => 'Growth']]));
        PlatformSetting::forgetCache();
        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
            'api.stripe.com/v1/products/prod_2' => Http::response(['id' => 'prod_2']),
        ]);

        app(Subscriptions::class)->syncPrices();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/products/prod_2') && $r['name'] === 'OrvynCRM Growth');
        $this->assertSame('Growth', app(PaymentSettings::class)->productName(2));
    }

    /** @return list<string> every address an e-mail went to, newest last */
    private function recipients(): array
    {
        return collect(Mail::mailer()->getSymfonyTransport()->messages()->all())
            ->flatMap(fn ($sent) => collect($sent->getOriginalMessage()->getTo())->map->getAddress())->values()->all();
    }

    private function bell(Tenant $tenant): int
    {
        return $tenant->run(fn () => DB::table('notifications_feed')->where('title', 'like', '%has changed%')->count());
    }

    public function test_the_diff_describes_what_changed_tier_by_tier(): void
    {
        $plans = app(Plans::class);
        $before = $plans->tiers();
        $plans->saveTiers($this->tiers([2 => ['name' => 'Growth', 'users' => 20]]));
        PlatformSetting::forgetCache();

        $diff = $plans->diff($before, $plans->tiers());

        $this->assertCount(1, $diff);
        $this->assertSame(['Tier 2', 'Growth'], [$diff[0]['was'], $diff[0]['name']]);
        $this->assertSame([['label' => 'Name', 'from' => 'Tier 2', 'to' => 'Growth'], ['label' => 'Active users', 'from' => '15', 'to' => '20']], $diff[0]['changes']);
        $this->assertSame([], $plans->diff($plans->tiers(), $plans->tiers()));
    }

    public function test_the_saver_chooses_whether_other_platform_administrators_and_company_administrators_are_told(): void
    {
        $onTier2 = $this->company('OnTwo', 'owner@two.test');
        $onTier1 = $this->company('OnOne', 'owner@one.test');
        app(Plans::class)->applyTier($onTier2, 2);
        AccountUser::create(['name' => 'Other Admin', 'email' => 'other-admin@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->platformAdmin();

        $this->patch('/platform/settings/plans', $this->form($this->tiers([2 => ['users' => 20]]), ['notify_platform' => true, 'notify_companies' => true, 'note' => 'More users, same price.']))->assertSessionHasNoErrors();

        $to = $this->recipients();
        $this->assertContains('other-admin@x.test', $to, 'another platform administrator is told');
        $this->assertNotContains('platform@x.test', $to, 'the person who made the change is not e-mailed about it');
        $this->assertContains('owner@two.test', $to, 'the administrators of a company on the changed tier are told');
        $this->assertNotContains('owner@one.test', $to, 'companies on other tiers are not');
        $this->assertSame([1, 0], [$this->bell($onTier2), $this->bell($onTier1)]);
    }

    public function test_nobody_is_told_unless_the_saver_chose_to_and_an_unchanged_save_tells_nobody(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        app(Plans::class)->applyTier($acme, 2);
        AccountUser::create(['name' => 'Other Admin', 'email' => 'other-admin@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->platformAdmin();

        $this->patch('/platform/settings/plans', $this->form($this->tiers([2 => ['users' => 20]])))->assertSessionHasNoErrors();
        $this->patch('/platform/settings/plans', $this->form($this->tiers([2 => ['users' => 20]]), ['notify_platform' => true, 'notify_companies' => true]))->assertSessionHasNoErrors();

        $this->assertSame([], $this->recipients());
        $this->assertSame(0, $this->bell($acme));
    }

    public function test_resetting_the_tiers_can_tell_people_too(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        app(Plans::class)->applyTier($acme, 2);
        app(Plans::class)->saveTiers($this->tiers([2 => ['name' => 'Growth']]));
        PlatformSetting::forgetCache();
        $this->platformAdmin();

        $this->post('/platform/settings/plans/reset', ['notify_companies' => true])->assertRedirect()->assertSessionHas('success');

        $this->assertContains('owner@acme.test', $this->recipients());
        $this->assertSame(1, $this->bell($acme));
    }
}
