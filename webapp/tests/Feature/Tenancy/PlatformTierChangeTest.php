<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\CompanySubscription;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/** A platform administrator moves a company to another tier: the explanation first, and what happens to a Stripe subscription. */
class PlatformTierChangeTest extends TenantTestCase
{
    private const SECRET = 'whsec_testsecret';

    private function platformAdmin(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    private function stripeOn(): void
    {
        $pay = app(PaymentSettings::class);
        $pay->save(['enabled' => true, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => self::SECRET]);
        app(Plans::class)->saveSettings(['trial_months' => 3, 'prices' => [1 => 29, 2 => 79, 3 => 149, 4 => 299, 5 => 499]]);
        foreach ([1, 2, 3, 4, 5] as $n) {
            $pay->savePrice($n, "prod_{$n}", "price_{$n}", 'sig');
        }
        PlatformSetting::forgetCache();
    }

    /** A company on tier 3 that pays through a running Stripe subscription. */
    private function paying(): Tenant
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        app(Plans::class)->applyTier($tenant, 3);
        CompanySubscription::create(['tenant_id' => $tenant->getTenantKey(), 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'status' => 'active', 'tier' => 3, 'stripe_price_id' => 'price_3', 'current_period_end' => now()->addDays(20)]);

        return $tenant->fresh();
    }

    private function fakeStripe(int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response($status === 200 ? ['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_x']]]]] : ['error' => ['message' => 'Your card was declined']], $status)]);
    }

    private function bell(Tenant $tenant): int
    {
        return $tenant->run(fn () => DB::table('notifications_feed')->where('title', 'like', '%Your plan is now%')->count());
    }

    public function test_the_preview_says_what_changes_and_that_a_company_without_stripe_only_gets_new_limits(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->get("/platform/companies/{$tenant->getTenantKey()}/tier-preview?tier=3")->assertOk()
            ->assertJsonPath('data.company', 'Acme')->assertJsonPath('data.to.tier', 3)->assertJsonPath('data.stripe', null)->assertJsonPath('data.down', false);
    }

    public function test_the_preview_warns_when_the_company_is_above_the_new_limits_and_shows_its_stripe_subscription(): void
    {
        $this->stripeOn();
        $tenant = $this->paying();
        $tenant->update(['max_users' => 3]);                                  // tier 1 below holds 5; pretend it already has more people than tier 1 allows
        $this->platformAdmin();

        $r = $this->get("/platform/companies/{$tenant->getTenantKey()}/tier-preview?tier=1")->assertOk()->assertJsonPath('data.down', true)->assertJsonPath('data.stripe.status', 'active')->assertJsonPath('data.stripe.price_ready', true);
        $this->assertIsArray($r->json('data.warnings'));
    }

    public function test_a_company_without_a_stripe_subscription_changes_tier_without_any_billing_choice_and_is_told(): void
    {
        Http::fake();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 4])->assertSessionHasNoErrors();

        $this->assertSame(4, (int) $tenant->fresh()->tier);
        $this->assertSame(1, $this->bell($tenant));
        Http::assertNothingSent();
    }

    public function test_the_administrators_are_not_told_when_the_platform_unticks_it(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 4, 'tier_tell' => false])->assertSessionHasNoErrors();

        $this->assertSame(4, (int) $tenant->fresh()->tier);
        $this->assertSame(0, $this->bell($tenant));
    }

    public function test_a_paying_company_needs_a_billing_choice_and_nothing_changes_without_one(): void
    {
        $this->stripeOn();
        Http::fake();
        $tenant = $this->paying();
        $this->platformAdmin();

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 4])->assertSessionHasErrors('tier_billing');

        $this->assertSame(3, (int) $tenant->fresh()->tier);
        Http::assertNothingSent();
    }

    public function test_moving_the_subscription_now_prorates_and_changes_the_tier(): void
    {
        $this->stripeOn();
        $this->fakeStripe();
        $tenant = $this->paying();
        $this->platformAdmin();

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 4, 'tier_billing' => 'stripe_now'])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions/sub_1') && $r->method() === 'POST' && $r['proration_behavior'] === 'create_prorations' && $r['items'][0]['price'] === 'price_4');
        $this->assertSame(4, (int) $tenant->fresh()->tier);
        $row = CompanySubscription::first();
        $this->assertSame([4, 'price_4', false], [(int) $row->tier, $row->stripe_price_id, (bool) $row->tier_override]);
        $this->assertSame(1, $this->bell($tenant));
    }

    public function test_moving_it_from_the_next_renewal_charges_nothing_now(): void
    {
        $this->stripeOn();
        $this->fakeStripe();
        $tenant = $this->paying();
        $this->platformAdmin();

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 2, 'tier_billing' => 'stripe_renewal'])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions/sub_1') && $r->method() === 'POST' && $r['proration_behavior'] === 'none' && $r['items'][0]['price'] === 'price_2');
        $this->assertSame(2, (int) $tenant->fresh()->tier);
    }

    public function test_when_stripe_refuses_nothing_changes_and_the_message_is_shown(): void
    {
        $this->stripeOn();
        $this->fakeStripe(402);
        $tenant = $this->paying();
        $this->platformAdmin();

        $r = $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 4, 'tier_billing' => 'stripe_now', 'name' => 'Renamed']);

        $r->assertSessionHasErrors('tier_billing');
        $this->assertStringContainsString('Nothing was changed', session('errors')->first('tier_billing'));
        $fresh = $tenant->fresh();
        $this->assertSame([3, 'Acme'], [(int) $fresh->tier, $fresh->name], 'not even the other fields were saved');
        $this->assertSame(0, $this->bell($tenant));
    }

    public function test_a_tier_without_a_stripe_price_cannot_be_billed_through_stripe(): void
    {
        $this->stripeOn();
        PlatformSetting::where('key', 'like', 'stripe.price.5%')->delete();
        PlatformSetting::forgetCache();
        Http::fake();
        $tenant = $this->paying();
        $this->platformAdmin();

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 5, 'tier_billing' => 'stripe_now'])->assertSessionHasErrors('tier_billing');

        $this->assertSame(3, (int) $tenant->fresh()->tier);
    }

    public function test_changing_only_the_limits_leaves_stripe_alone_and_stripe_events_do_not_undo_it(): void
    {
        $this->stripeOn();
        Http::fake();
        $tenant = $this->paying();
        $this->platformAdmin();

        $this->patch("/platform/companies/{$tenant->getTenantKey()}", ['tier' => 5, 'tier_billing' => 'app_only'])->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $this->assertSame(5, (int) $tenant->fresh()->tier);
        $this->assertTrue((bool) CompanySubscription::first()->tier_override);

        // Stripe still says tier 3 (nothing was moved there): the company stays on 5
        $this->deliver($this->subscriptionEvent($tenant, 3, 'evt_a'))->assertOk();
        $this->assertSame(5, (int) $tenant->fresh()->tier);
        $this->assertTrue((bool) CompanySubscription::first()->tier_override);

        // somebody moves the subscription in Stripe to tier 2: Stripe is the truth again
        $this->deliver($this->subscriptionEvent($tenant, 2, 'evt_b'))->assertOk();
        $this->assertSame(2, (int) $tenant->fresh()->tier);
        $this->assertFalse((bool) CompanySubscription::first()->tier_override);
    }

    /** @return array<string, mixed> */
    private function subscriptionEvent(Tenant $tenant, int $tier, string $id): array
    {
        return ['id' => $id, 'type' => 'customer.subscription.updated', 'data' => ['object' => [
            'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active', 'current_period_end' => now()->addMonth()->timestamp, 'cancel_at_period_end' => false,
            'metadata' => ['tenant_id' => $tenant->getTenantKey(), 'tier' => (string) $tier],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => "price_{$tier}", 'metadata' => ['tier' => (string) $tier]]]]],
        ]]];
    }

    private function deliver(array $event)
    {
        $payload = json_encode($event);
        $t = time();

        return $this->call('POST', '/api/v1/hooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, self::SECRET),
        ], $payload);
    }
}
