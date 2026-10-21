<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\CompanySubscription;
use App\Models\PlatformSetting;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Subscriptions;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

/** Cancelling a subscription from the company and from the platform, and asking to subscribe again afterwards. */
class SubscriptionCancellationTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
        app(PaymentSettings::class)->save(['enabled' => true, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => 'whsec_testsecret']);
        PlatformSetting::forgetCache();
    }

    private function signIn(string $email = 'owner@acme.test', string $password = 'Owner-Passw0rd-1'): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => $email, 'password' => $password]);
        $this->bearerFor($email, $password);
    }

    private function subscribed(Tenant $tenant, int $tier = 3): CompanySubscription
    {
        app(Plans::class)->applyTier($tenant, $tier);

        return CompanySubscription::create([
            'tenant_id' => $tenant->getTenantKey(), 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'status' => 'active',
            'tier' => $tier, 'current_period_end' => now()->addDays(20),
        ]);
    }

    private function platformAdmin(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->signIn('platform@x.test', 'Platform-Passw0rd-1');
    }

    /** @return array<string, mixed> */
    private function deletedEvent(Tenant $tenant, string $id = 'evt_del'): array
    {
        return ['id' => $id, 'type' => 'customer.subscription.deleted', 'data' => ['object' => [
            'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'canceled', 'metadata' => ['tenant_id' => $tenant->getTenantKey(), 'tier' => '3'],
        ]]];
    }

    private function deliver(array $event)
    {
        $payload = json_encode($event);
        $t = time();

        return $this->call('POST', '/api/v1/hooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_testsecret'),
        ], $payload);
    }

    public function test_a_company_cancels_at_the_end_of_the_period_and_keeps_its_plan_until_then(): void
    {
        Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1'])]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $sub = $this->subscribed($tenant);
        $this->signIn();

        $this->postJson('/api/v1/billing/cancel', ['reason' => 'Too expensive'])->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/subscriptions/sub_1') && $r['cancel_at_period_end'] === 'true');
        $sub->refresh();
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertSame(['company', 'Too expensive'], [$sub->canceled_by, $sub->cancel_reason]);
        $this->assertSame(3, (int) $tenant->fresh()->tier, 'the plan stays until the period ends');
        $this->assertTrue($sub->isGood());
    }

    public function test_a_cancellation_can_be_taken_back_before_it_ends(): void
    {
        Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1'])]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $sub = $this->subscribed($tenant);
        $sub->update(['cancel_at_period_end' => true, 'canceled_by' => 'company']);
        $this->signIn();

        $this->postJson('/api/v1/billing/resume')->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', false);

        Http::assertSent(fn (Request $r) => $r['cancel_at_period_end'] === 'false');
        $this->assertNull($sub->fresh()->canceled_by);
    }

    public function test_only_an_administrator_cancels_and_only_a_running_subscription_can_be_cancelled(): void
    {
        Http::fake();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->signIn();

        $this->postJson('/api/v1/billing/cancel')->assertUnprocessable();                                  // nothing to cancel

        $this->subscribed($tenant);
        $tenant->run(fn () => app(TenantProvisioner::class)->addMember($tenant, app(TenantProvisioner::class)->identity('Worker', 'worker@acme.test', 'Worker-Passw0rd-1'), 'user'));
        $this->signIn('worker@acme.test', 'Worker-Passw0rd-1');
        $this->postJson('/api/v1/billing/cancel')->assertForbidden();
        $this->postJson('/api/v1/billing/resume')->assertForbidden();
        $this->postJson('/api/v1/billing/resubscribe')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_when_stripe_ends_the_subscription_the_company_goes_back_to_the_first_tier_and_needs_permission(): void
    {
        Http::fake();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant)->update(['cancel_at_period_end' => true, 'canceled_by' => 'company']);

        $this->deliver($this->deletedEvent($tenant))->assertOk();

        $sub = CompanySubscription::first();
        $this->assertSame(['canceled', false], [$sub->status, $sub->resubscribe_enabled]);
        $this->assertSame([1, 5], [(int) $tenant->fresh()->tier, (int) $tenant->fresh()->max_users]);

        $this->signIn();
        $this->postJson('/api/v1/billing/checkout', ['tier' => 2])->assertUnprocessable();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.subscription.ended', true)->assertJsonPath('data.subscription.can_subscribe', false);
    }

    public function test_the_company_asks_to_subscribe_again_once_and_the_platform_is_told(): void
    {
        Http::fake();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant);
        $this->signIn();

        $this->postJson('/api/v1/billing/resubscribe', ['tier' => 3])->assertUnprocessable();               // still subscribed

        $this->deliver($this->deletedEvent($tenant))->assertOk();
        $this->postJson('/api/v1/billing/resubscribe', ['tier' => 3, 'message' => 'We are back'])->assertOk()->assertJsonPath('data.subscription.resubscribe_requested', true);
        $this->postJson('/api/v1/billing/resubscribe', ['tier' => 3])->assertUnprocessable();               // already asked

        $request = SubscriptionRequest::first();
        $this->assertSame(['pending', 3, 'We are back'], [$request->status, $request->tier, $request->message]);
    }

    public function test_the_platform_enables_resubscribing_and_the_pending_request_is_closed(): void
    {
        Http::fake();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant);
        $this->deliver($this->deletedEvent($tenant))->assertOk();
        $this->signIn();
        $this->postJson('/api/v1/billing/resubscribe', ['tier' => 2])->assertOk();

        $this->platformAdmin();
        $request = SubscriptionRequest::first();
        $this->get('/platform/requests')->assertInertia(fn ($p) => $p->has('subscription_requests', 1)->where('subscription_requests.0.status', 'pending'));
        $this->post("/platform/subscription-requests/{$request->id}/approve", ['response' => 'Welcome back'])->assertRedirect();

        $this->assertTrue((bool) CompanySubscription::first()->resubscribe_enabled);
        $this->assertSame(['approved', 'Welcome back'], [$request->fresh()->status, $request->fresh()->response]);

        $this->signIn();
        $this->getJson('/api/v1/billing')->assertJsonPath('data.subscription.can_subscribe', true);
    }

    public function test_the_platform_can_decline_a_request_and_enable_without_one(): void
    {
        Http::fake();
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant);
        $this->deliver($this->deletedEvent($tenant));
        $this->signIn();
        $this->postJson('/api/v1/billing/resubscribe')->assertOk();

        $this->platformAdmin();
        $request = SubscriptionRequest::first();
        $this->post("/platform/subscription-requests/{$request->id}/decline", ['response' => 'Not now'])->assertRedirect();
        $this->assertSame('declined', $request->fresh()->status);
        $this->post("/platform/subscription-requests/{$request->id}/decline")->assertStatus(422);              // handled once

        $this->post("/platform/companies/{$tenant->getTenantKey()}/subscription/enable")->assertRedirect();
        $this->assertTrue((bool) CompanySubscription::first()->resubscribe_enabled);
    }

    public function test_the_platform_cancels_at_once_and_the_company_is_on_the_first_tier_immediately(): void
    {
        Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'canceled'])]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant);
        $this->platformAdmin();

        $this->post("/platform/companies/{$tenant->getTenantKey()}/subscription/cancel", ['immediately' => true, 'reason' => 'Chargeback'])->assertRedirect();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1/subscriptions/sub_1'));
        $sub = CompanySubscription::first();
        $this->assertSame(['canceled', 'platform', 'Chargeback', false], [$sub->status, $sub->canceled_by, $sub->cancel_reason, $sub->resubscribe_enabled]);
        $this->assertSame(1, (int) $tenant->fresh()->tier);
    }

    public function test_the_platform_cancels_at_period_end_and_can_withdraw_it(): void
    {
        Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1'])]);
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant);
        $this->platformAdmin();

        $this->post("/platform/companies/{$tenant->getTenantKey()}/subscription/cancel", ['tell' => false])->assertRedirect();
        $this->assertTrue((bool) CompanySubscription::first()->cancel_at_period_end);
        $this->assertSame(3, (int) $tenant->fresh()->tier);

        $this->post("/platform/companies/{$tenant->getTenantKey()}/subscription/resume")->assertRedirect();
        $this->assertFalse((bool) CompanySubscription::first()->cancel_at_period_end);
    }

    public function test_only_platform_administrators_use_the_platform_controls(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant);
        $this->signIn();

        $this->post("/platform/companies/{$tenant->getTenantKey()}/subscription/cancel")->assertForbidden();
        $this->post("/platform/companies/{$tenant->getTenantKey()}/subscription/enable")->assertForbidden();
    }

    public function test_allowing_a_company_to_subscribe_again_needs_a_subscription_that_has_ended(): void
    {
        $tenant = $this->company('Acme', 'owner@acme.test');
        $this->subscribed($tenant);

        $this->expectException(ValidationException::class);
        app(Subscriptions::class)->enableResubscribe($tenant);
    }
}
