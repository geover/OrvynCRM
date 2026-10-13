<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\CompanyBilling;
use App\Models\PlatformSetting;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\SmsNumbers;
use App\Services\Tenancy\TenantProvisioner;
use App\Services\Tenancy\TrialReminders;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

/** Licence tiers, the free trial for sign-ups, billing details, and the limits a tier brings. */
class PlansTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
    }

    /** @return array<string, mixed> */
    private function billing(array $over = []): array
    {
        return $over + [
            'legal_name' => 'Acme LLC', 'contact_name' => 'Ana Reyes', 'contact_email' => 'billing@acme.test', 'address_line1' => '1 Main St',
            'city' => 'Manila', 'postal_code' => '1000', 'country' => 'ph',
        ];
    }

    private function signIn(string $email = 'owner@acme.test', string $password = 'Owner-Passw0rd-1'): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    private function trialCompany(string $name = 'Acme', string $email = 'owner@acme.test'): Tenant
    {
        $tenant = $this->company($name, $email);
        app(Plans::class)->startTrial($tenant);

        return $tenant->fresh();
    }

    public function test_the_tiers_carry_the_limits_the_plan_promises(): void
    {
        $tiers = collect(app(Plans::class)->tiers())->keyBy('tier');

        $this->assertSame([5, 15, 50, 100, null], $tiers->pluck('users')->values()->all());
        $this->assertSame([100, 500, 1000, 3000, 5000], $tiers->pluck('sms_per_month')->values()->all());
        $this->assertSame([1, 3, 5, 10, 20], $tiers->pluck('sms_numbers')->values()->all());
    }

    public function test_a_trial_starts_on_the_first_tier_and_lasts_the_months_the_platform_chose(): void
    {
        $plans = app(Plans::class);
        $this->assertSame(3, $plans->trialMonths());

        $tenant = $this->trialCompany();
        $this->assertSame(1, (int) $tenant->tier);
        $this->assertSame(5, (int) $tenant->max_users);
        $this->assertEqualsWithDelta(now()->addMonths(3)->timestamp, $tenant->trial_ends_at->timestamp, 5);

        $plans->saveSettings(['trial_months' => 6, 'prices' => []]);
        PlatformSetting::forgetCache();
        $other = $this->company('Beta', 'owner@beta.test');
        $plans->startTrial($other);
        $this->assertEqualsWithDelta(now()->addMonths(6)->timestamp, $other->fresh()->trial_ends_at->timestamp, 5);
    }

    public function test_a_company_made_by_hand_has_no_trial_and_a_tier_sets_its_user_limit(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->assertNull(Tenant::first()->trial_ends_at);

        $plans = app(Plans::class);
        $tenant = Tenant::first();
        $plans->applyTier($tenant, 3);
        $this->assertSame(50, (int) $tenant->fresh()->max_users);

        $plans->applyTier($tenant, 5);
        $this->assertTrue(Plans::isUnlimitedSeats((int) $tenant->fresh()->max_users));
    }

    public function test_a_company_that_never_had_a_trial_is_never_forced_to_give_billing_details(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');

        $this->assertFalse(app(Plans::class)->isBlocked($tenant));
        $this->signIn();
        $this->get('/contacts')->assertOk();
    }

    public function test_a_running_trial_blocks_nothing(): void
    {
        $this->trialCompany();
        $this->signIn();

        $this->get('/contacts')->assertOk();
        $this->get('/billing')->assertOk();
    }

    public function test_when_the_trial_has_ended_only_the_billing_page_stays_open(): void
    {
        $tenant = $this->trialCompany();
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        $this->signIn();

        $this->get('/contacts')->assertRedirect('/billing');
        $this->getJson('/api/v1/contacts')->assertStatus(402)->assertJson(['code' => 'billing_required']);
        $this->get('/billing')->assertOk();
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.trial.state', 'expired');
        $this->get('/manual')->assertOk();
    }

    public function test_giving_complete_billing_details_opens_the_company_again(): void
    {
        $tenant = $this->trialCompany();
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        $this->signIn();

        $this->putJson('/api/v1/billing', $this->billing(['city' => '']))->assertUnprocessable();
        $this->get('/contacts')->assertRedirect('/billing');

        $this->putJson('/api/v1/billing', $this->billing())->assertOk()->assertJsonPath('data.trial.state', 'subscribed');
        $this->get('/contacts')->assertOk();
        $this->assertSame('PH', CompanyBilling::first()->country);
    }

    public function test_billing_details_can_be_given_during_the_trial(): void
    {
        $this->trialCompany();
        $this->signIn();

        $this->putJson('/api/v1/billing', $this->billing())->assertOk()->assertJsonPath('data.trial.billing_complete', true)->assertJsonPath('data.trial.state', 'trial');
    }

    public function test_only_an_administrator_changes_billing_or_the_plan_and_others_do_not_see_the_details(): void
    {
        $tenant = $this->trialCompany();
        $this->signIn();
        $this->putJson('/api/v1/billing', $this->billing());
        $tenant->run(function () {
            app(TenantProvisioner::class)->addMember(
                Tenant::first(), app(TenantProvisioner::class)->identity('Worker', 'worker@acme.test', 'Worker-Passw0rd-1'), 'user',
            );
        });

        $this->signIn('worker@acme.test', 'Worker-Passw0rd-1');
        $this->putJson('/api/v1/billing', $this->billing())->assertForbidden();
        $this->postJson('/api/v1/billing/plan', ['tier' => 2])->assertForbidden();
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonMissingPath('data.billing')->assertJsonMissingPath('data.tiers');
    }

    public function test_choosing_a_plan_needs_billing_details_and_a_smaller_plan_must_fit(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->signIn();

        $this->postJson('/api/v1/billing/plan', ['tier' => 2])->assertUnprocessable();                     // no billing details yet
        $this->putJson('/api/v1/billing', $this->billing());
        $this->postJson('/api/v1/billing/plan', ['tier' => 2])->assertOk()->assertJsonPath('data.tier.tier', 2);
        $this->assertSame(15, (int) $tenant->fresh()->max_users);

        $this->putJson('/api/v1/integrations/sms-numbers', ['numbers' => ['+15550000001', '+15550000002']])->assertOk();
        $this->postJson('/api/v1/billing/plan', ['tier' => 1])->assertUnprocessable();                      // two numbers do not fit one
        $this->putJson('/api/v1/integrations/sms-numbers', ['numbers' => ['+15550000001']])->assertOk();
        $this->postJson('/api/v1/billing/plan', ['tier' => 1])->assertOk();
        $this->assertSame(5, (int) $tenant->fresh()->max_users);
    }

    public function test_a_company_can_use_only_as_many_texting_numbers_as_its_tier_allows(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');

        $tenant->run(function () {
            $numbers = app(SmsNumbers::class);
            $this->assertSame(['+15550000001'], $numbers->save(['+15550000001', '+1 555 000 0001']));       // the same number twice counts once
            try {
                $numbers->save(['+15550000001', '+15550000002']);
                $this->fail('Tier 1 allows one number.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('numbers', $e->errors());
            }
            $this->assertSame('+15550000001', $numbers->assertUsableForText('+15550000001'));
        });

        app(Plans::class)->applyTier($tenant, 2);
        $tenant->run(fn () => $this->assertCount(2, app(SmsNumbers::class)->save(['+15550000001', '+15550000002'])));
    }

    public function test_a_text_may_only_be_sent_from_one_of_the_companys_numbers(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $tenant->run(function () {
            app(SmsNumbers::class)->save(['+15550000001']);

            $this->expectException(ValidationException::class);
            app(SmsNumbers::class)->assertUsableForText('+15559999999');
        });
    }

    public function test_the_monthly_text_limit_counts_what_went_out_this_month_only(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $this->assertSame(100, $plans->smsLimit($tenant));

        $tenant->run(function () {
            foreach (range(1, 3) as $i) {
                SmsMessage::create(['to_number' => '+1555000'.$i, 'body' => 'Hi', 'status' => 'sent', 'sent_at' => now()]);
            }
            SmsMessage::create(['to_number' => '+15550009999', 'body' => 'Failed', 'status' => 'failed']);
            SmsMessage::create(['to_number' => '+15550008888', 'body' => 'Waiting', 'status' => 'queued']);
            $old = SmsMessage::create(['to_number' => '+15550007777', 'body' => 'Old', 'status' => 'sent', 'sent_at' => now()->subMonths(2)]);
            $old->forceFill(['updated_at' => now()->subMonths(2)])->saveQuietly();
        });

        $this->assertSame(3, $plans->smsUsed($tenant));
        $this->assertFalse($plans->smsExhausted($tenant));

        $tenant->run(fn () => collect(range(1, 97))->each(fn ($i) => SmsMessage::create(['to_number' => '+16660'.$i, 'body' => 'x', 'status' => 'sent', 'sent_at' => now()])));
        $this->assertTrue($plans->smsExhausted($tenant));
    }

    public function test_the_platform_sets_prices_and_the_trial_length_and_only_platform_administrators_may(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->patch('/platform/settings/plans', ['trial_months' => 3, 'prices' => []])->assertRedirect('/login');

        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->signIn('platform@x.test', 'Platform-Passw0rd-1');

        $this->patch('/platform/settings/plans', ['trial_months' => 0, 'prices' => []])->assertSessionHasErrors('trial_months');
        $this->patch('/platform/settings/plans', ['trial_months' => 2, 'prices' => [1 => '29', 2 => '79.5', 3 => '', 4 => '299', 5 => '499']])->assertSessionHasNoErrors();

        PlatformSetting::forgetCache();
        $plans = app(Plans::class);
        $this->assertSame(2, $plans->trialMonths());
        $this->assertSame([29.0, 79.5, null, 299.0, 499.0], collect($plans->tiers())->pluck('price')->all());
        $this->get('/platform/settings/plans')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/SettingsPlans')->has('tiers', 5));
    }

    public function test_a_platform_administrator_changes_a_tier_and_the_trial_of_a_company(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->signIn('platform@x.test', 'Platform-Passw0rd-1');

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 4, 'trial_ends_at' => now()->addDays(10)->toDateString()])->assertRedirect();
        $tenant->refresh();
        $this->assertSame(4, (int) $tenant->tier);
        $this->assertSame(100, (int) $tenant->max_users);
        $this->assertNotNull($tenant->trial_ends_at);

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 2, 'max_users' => 40])->assertRedirect();      // an explicit limit above the tier wins
        $this->assertSame(40, (int) $tenant->fresh()->max_users);

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['trial_ends_at' => null])->assertRedirect();
        $this->assertNull($tenant->fresh()->trial_ends_at);
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('companies.0.tier', 2)->where('companies.0.trial.state', 'none'));
    }

    public function test_the_platform_can_start_a_company_on_a_tier_with_a_trial(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->signIn('platform@x.test', 'Platform-Passw0rd-1');

        $this->post('/platform/companies', ['name' => 'Gamma', 'owner_name' => 'Gus', 'owner_email' => 'gus@gamma.test', 'owner_password' => 'Gamma-Passw0rd-1', 'tier' => 3])->assertRedirect();
        $gamma = Tenant::where('slug', 'gamma')->first();
        $this->assertSame([3, 50], [(int) $gamma->tier, (int) $gamma->max_users]);
        $this->assertNull($gamma->trial_ends_at);

        $this->post('/platform/companies', ['name' => 'Delta', 'owner_name' => 'Dee', 'owner_email' => 'dee@delta.test', 'owner_password' => 'Delta-Passw0rd-1', 'trial' => true])->assertRedirect();
        $this->assertNotNull(Tenant::where('slug', 'delta')->first()->trial_ends_at);
    }

    public function test_trial_reminders_go_out_once_at_each_stage_and_not_after_billing_details(): void
    {
        $tenant = $this->trialCompany();
        $reminders = app(TrialReminders::class);

        $this->assertSame(0, $reminders->run());                                                                   // three months away: nothing to say

        $tenant->update(['trial_ends_at' => now()->addDays(10)]);
        $this->assertSame(1, $reminders->run());
        $this->assertSame(0, $reminders->run());                                                                   // the same stage is told once

        $tenant->update(['trial_ends_at' => now()->addDay()]);
        $this->assertSame(1, $reminders->run());

        $tenant->update(['trial_ends_at' => now()->subHour()]);
        $this->assertSame(1, $reminders->run());
        $this->assertSame(4, (int) $tenant->fresh()->trial_notice_stage);
        $this->assertSame(0, $reminders->run());

        $messages = Mail::mailer()->getSymfonyTransport()->messages()->all();
        $this->assertNotEmpty($messages);
    }

    public function test_a_company_with_billing_details_is_not_reminded(): void
    {
        $tenant = $this->trialCompany();
        CompanyBilling::create(['tenant_id' => $tenant->getTenantKey()] + $this->billing());
        CompanyBilling::first()->update(['completed_at' => now()]);
        $tenant->update(['trial_ends_at' => now()->addDays(3)]);

        $this->assertSame(0, app(TrialReminders::class)->run());
    }

    public function test_the_summary_reports_users_texts_and_the_trial(): void
    {
        $tenant = $this->trialCompany();
        $summary = app(Plans::class)->summary($tenant);

        $this->assertSame('trial', $summary['trial']['state']);
        $this->assertSame(['used' => 1, 'max' => 5], $summary['users']);
        $this->assertSame(['used' => 0, 'max' => 100], $summary['sms']);
    }

    public function test_the_users_allowed_of_a_company_can_be_raised_above_its_tier_but_not_set_below_it(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->signIn('platform@x.test', 'Platform-Passw0rd-1');
        $url = "/platform/companies/{$tenant->getTenantKey()}";

        $this->patch($url, ['max_users' => 3])->assertSessionHasErrors('max_users');                       // Tier 1 includes 5
        $this->patch($url, ['max_users' => 8])->assertSessionHasNoErrors();                                // extra users above the tier are fine
        $this->assertSame(8, (int) $tenant->fresh()->max_users);
        $this->patch($url, ['tier' => 3, 'max_users' => 40])->assertSessionHasErrors('max_users');         // Tier 3 includes 50
        $this->patch($url, ['tier' => 3, 'max_users' => 60])->assertSessionHasNoErrors();
        $this->assertSame([3, 60], [(int) $tenant->fresh()->tier, (int) $tenant->fresh()->max_users]);

        $this->post('/platform/companies', ['name' => 'Gamma', 'owner_name' => 'Gus', 'owner_email' => 'gus@gamma.test', 'owner_password' => 'Gamma-Passw0rd-1', 'tier' => 2, 'max_users' => 10])->assertSessionHasErrors('max_users');
        $this->post('/platform/companies', ['name' => 'Delta', 'owner_name' => 'Dee', 'owner_email' => 'dee@delta.test', 'owner_password' => 'Delta-Passw0rd-1', 'tier' => 2, 'max_users' => 20])->assertRedirect();
        $this->assertSame(20, (int) Tenant::where('slug', 'delta')->first()->max_users);
    }
}
