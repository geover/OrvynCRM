<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\CompanyBilling;
use App\Models\CompanySubscription;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Subscriptions;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/** Stripe: the platform's account and prices, Checkout for a company, and what Stripe's webhooks change. */
class StripePaymentsTest extends TenantTestCase
{
    private const SECRET = 'whsec_testsecret';

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
    }

    private function stripeOn(bool $requirePayment = false): void
    {
        app(PaymentSettings::class)->save(['enabled' => true, 'require_payment' => $requirePayment, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => self::SECRET]);
        PlatformSetting::forgetCache();
    }

    private function priced(): void
    {
        app(Plans::class)->saveSettings(['trial_months' => 3, 'prices' => [1 => 29, 2 => 79.5, 3 => 149, 4 => 299, 5 => 499]]);
        PlatformSetting::forgetCache();
    }

    private function signIn(string $email = 'owner@acme.test', string $password = 'Owner-Passw0rd-1'): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    /** @param array<string, mixed> $event */
    private function deliver(array $event, ?string $secret = self::SECRET)
    {
        $payload = json_encode($event);
        $t = time();

        return $this->call('POST', '/api/v1/hooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, $secret),
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function subscriptionEvent(Tenant $tenant, string $type = 'customer.subscription.created', string $status = 'active', int $tier = 3, string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['object' => [
            'id' => 'sub_1', 'customer' => 'cus_1', 'status' => $status, 'current_period_end' => now()->addMonth()->timestamp, 'cancel_at_period_end' => false,
            'metadata' => ['tenant_id' => $tenant->getTenantKey(), 'tier' => (string) $tier],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['metadata' => ['tier' => (string) $tier]]]]],
        ]]];
    }

    public function test_a_webhook_without_a_valid_signature_is_refused(): void
    {
        $this->stripeOn();
        $tenant = $this->company('Acme', 'owner@acme.test');

        $this->deliver($this->subscriptionEvent($tenant), 'whsec_wrong')->assertStatus(400);
        $this->postJson('/api/v1/hooks/stripe', $this->subscriptionEvent($tenant))->assertStatus(400);
        $this->assertSame(0, CompanySubscription::count());
    }

    public function test_a_subscription_event_records_the_subscription_and_puts_the_company_on_the_tier(): void
    {
        $this->stripeOn();
        Http::fake(['api.stripe.com/*' => Http::response(['message' => 'unavailable'], 500)]);        // the card lookup is optional
        $tenant = $this->company('Acme', 'owner@acme.test');

        $this->deliver($this->subscriptionEvent($tenant))->assertOk();

        $sub = CompanySubscription::first();
        $this->assertSame(['active', 3, 'cus_1'], [$sub->status, $sub->tier, $sub->stripe_customer_id]);
        $this->assertTrue($sub->isGood());
        $this->assertSame([3, 50], [(int) $tenant->fresh()->tier, (int) $tenant->fresh()->max_users]);
    }

    public function test_the_same_event_is_applied_only_once(): void
    {
        $this->stripeOn();
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $tenant = $this->company('Acme', 'owner@acme.test');

        $this->deliver($this->subscriptionEvent($tenant))->assertOk();
        CompanySubscription::first()->update(['status' => 'past_due']);

        $this->deliver($this->subscriptionEvent($tenant))->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame('past_due', CompanySubscription::first()->status);
    }

    public function test_a_cancelled_subscription_no_longer_counts_as_paying(): void
    {
        $this->stripeOn();
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->deliver($this->subscriptionEvent($tenant))->assertOk();
        $this->assertTrue(app(Plans::class)->isPaying($tenant));

        $this->deliver($this->subscriptionEvent($tenant, 'customer.subscription.deleted', 'canceled', 3, 'evt_2'))->assertOk();

        $this->assertSame('canceled', CompanySubscription::first()->status);
        $this->assertFalse(app(Plans::class)->isPaying($tenant));
    }

    public function test_a_subscription_opens_a_company_whose_trial_has_ended(): void
    {
        $this->stripeOn(requirePayment: true);
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        app(Plans::class)->startTrial($tenant);
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        CompanyBilling::create(['tenant_id' => $tenant->getTenantKey(), 'legal_name' => 'Acme', 'contact_name' => 'Ana', 'contact_email' => 'a@acme.test', 'address_line1' => '1 St', 'city' => 'Manila', 'postal_code' => '1000', 'country' => 'PH', 'completed_at' => now()]);

        $this->assertTrue(app(Plans::class)->isBlocked($tenant->fresh()), 'details alone are not enough when a payment method is required');
        $this->deliver($this->subscriptionEvent($tenant))->assertOk();
        $this->assertFalse(app(Plans::class)->isBlocked($tenant->fresh()));
    }

    public function test_billing_details_are_enough_when_the_platform_does_not_require_a_payment_method(): void
    {
        $this->stripeOn(requirePayment: false);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        CompanyBilling::create(['tenant_id' => $tenant->getTenantKey(), 'legal_name' => 'Acme', 'contact_name' => 'Ana', 'contact_email' => 'a@acme.test', 'address_line1' => '1 St', 'city' => 'Manila', 'postal_code' => '1000', 'country' => 'PH', 'completed_at' => now()]);

        $this->assertFalse(app(Plans::class)->isBlocked($tenant->fresh()));
    }

    public function test_the_prices_of_the_tiers_are_made_on_stripe_once_and_renewed_when_they_change(): void
    {
        $this->stripeOn();
        $this->priced();
        Http::fake([
            'api.stripe.com/v1/products' => Http::sequence()->push(['id' => 'prod_1'])->push(['id' => 'prod_2'])->push(['id' => 'prod_3'])->push(['id' => 'prod_4'])->push(['id' => 'prod_5']),
            'api.stripe.com/v1/prices' => Http::sequence()->push(['id' => 'price_1'])->push(['id' => 'price_2'])->push(['id' => 'price_3'])->push(['id' => 'price_4'])->push(['id' => 'price_5'])->push(['id' => 'price_2b']),
            'api.stripe.com/v1/prices/*' => Http::response(['id' => 'price_2', 'active' => false]),
        ]);

        $first = app(Subscriptions::class)->syncPrices();
        $this->assertSame([1, 2, 3, 4, 5], $first['created']);
        $this->assertSame('price_2', app(PaymentSettings::class)->priceId(2));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/prices') && $r['unit_amount'] === 7950 && $r['currency'] === 'usd' && $r['recurring']['interval'] === 'month');

        $again = app(Subscriptions::class)->syncPrices();
        $this->assertSame([1, 2, 3, 4, 5], $again['unchanged']);

        app(Plans::class)->saveSettings(['trial_months' => 3, 'prices' => [1 => 29, 2 => 89, 3 => 149, 4 => 299, 5 => 499]]);
        PlatformSetting::forgetCache();
        $renewed = app(Subscriptions::class)->syncPrices();

        $this->assertSame([2], $renewed['created']);
        $this->assertSame('price_2b', app(PaymentSettings::class)->priceId(2));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/prices/price_2') && $r['active'] === 'false');
    }

    public function test_a_tier_without_a_price_is_not_sold_online(): void
    {
        $this->stripeOn();
        Http::fake();

        $result = app(Subscriptions::class)->syncPrices();

        $this->assertSame([1, 2, 3, 4, 5], $result['skipped']);
        Http::assertNothingSent();
    }

    public function test_checkout_sends_the_company_to_stripe_and_keeps_the_trial_running(): void
    {
        $this->stripeOn();
        $this->priced();
        app(PaymentSettings::class)->savePrice(2, 'prod_2', 'price_2', 'sig');
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        app(Plans::class)->startTrial($tenant);
        $this->signIn();

        $this->postJson('/api/v1/billing/checkout', ['tier' => 2])->assertOk()->assertJson(['url' => 'https://checkout.stripe.com/c/pay/cs_1']);

        Http::assertSent(function (Request $r) use ($tenant) {
            return str_ends_with($r->url(), '/v1/checkout/sessions')
                && $r['mode'] === 'subscription' && $r['line_items'][0]['price'] === 'price_2' && $r['client_reference_id'] === $tenant->getTenantKey()
                && $r['subscription_data']['metadata']['tier'] === 2 && isset($r['subscription_data']['trial_end']) && $r['customer_email'] === 'owner@acme.test';
        });
    }

    public function test_checkout_is_refused_for_a_tier_that_is_not_on_stripe_and_for_a_company_already_subscribed(): void
    {
        $this->stripeOn();
        Http::fake();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->signIn();

        $this->postJson('/api/v1/billing/checkout', ['tier' => 3])->assertUnprocessable();

        $this->priced();
        app(PaymentSettings::class)->savePrice(3, 'prod_3', 'price_3', 'sig');
        CompanySubscription::create(['tenant_id' => $tenant->getTenantKey(), 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'status' => 'active']);
        $this->postJson('/api/v1/billing/checkout', ['tier' => 3])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_only_an_administrator_starts_checkout_or_the_portal(): void
    {
        $this->stripeOn();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $tenant->run(fn () => app(TenantProvisioner::class)->addMember($tenant, app(TenantProvisioner::class)->identity('Worker', 'worker@acme.test', 'Worker-Passw0rd-1'), 'user'));
        $this->signIn('worker@acme.test', 'Worker-Passw0rd-1');

        $this->postJson('/api/v1/billing/checkout', ['tier' => 2])->assertForbidden();
        $this->postJson('/api/v1/billing/portal')->assertForbidden();
    }

    public function test_a_subscribed_company_changes_tier_at_stripe_and_one_without_a_subscription_must_subscribe(): void
    {
        $this->stripeOn();
        $this->priced();
        app(PaymentSettings::class)->savePrice(3, 'prod_3', 'price_3', 'sig');
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_1']]]]),
        ]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->signIn();

        $this->postJson('/api/v1/billing/plan', ['tier' => 3])->assertUnprocessable();                              // not subscribed: Checkout is the way in

        CompanySubscription::create(['tenant_id' => $tenant->getTenantKey(), 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'status' => 'active', 'tier' => 1]);
        $this->postJson('/api/v1/billing/plan', ['tier' => 3])->assertOk()->assertJsonPath('data.tier.tier', 3);

        $this->assertSame(50, (int) $tenant->fresh()->max_users);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions/sub_1') && $r->method() === 'POST' && $r['items'][0]['price'] === 'price_3' && $r['proration_behavior'] === 'create_prorations');
    }

    public function test_the_portal_needs_a_customer(): void
    {
        $this->stripeOn();
        Http::fake(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/x'])]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->signIn();

        $this->postJson('/api/v1/billing/portal')->assertUnprocessable();

        CompanySubscription::create(['tenant_id' => $tenant->getTenantKey(), 'stripe_customer_id' => 'cus_1', 'status' => 'active']);
        $this->postJson('/api/v1/billing/portal')->assertOk()->assertJson(['url' => 'https://billing.stripe.com/p/session/x']);
    }

    public function test_the_platform_saves_the_keys_encrypted_and_only_platform_administrators_may(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->patch('/platform/settings/payments', ['enabled' => true, 'require_payment' => false])->assertRedirect('/login');

        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->signIn('platform@x.test', 'Platform-Passw0rd-1');

        $this->patch('/platform/settings/payments', ['enabled' => true, 'require_payment' => false, 'secret_key' => 'not-a-key'])->assertSessionHasErrors('secret_key');
        $this->patch('/platform/settings/payments', ['enabled' => true, 'require_payment' => true, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => 'whsec_abc'])->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('sk_test_abc123', (string) PlatformSetting::get('stripe.secret_key'));
        PlatformSetting::forgetCache();
        $settings = app(PaymentSettings::class);
        $this->assertSame(['test', true, true], [$settings->mode(), $settings->requirePayment(), $settings->current()['key_set']]);
        $this->get('/platform/settings/payments')->assertOk()->assertDontSee('sk_test_abc123')->assertInertia(fn ($p) => $p->component('Platform/SettingsPayments')->where('settings.key_hint', '••••c123'));
    }
}
