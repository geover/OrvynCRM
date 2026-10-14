<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\SeatRequest;
use App\Models\Tenant;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** A company asking the platform for more texts per month, and the platform answering from its Requests page. */
class TextRequestTest extends TenantTestCase
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
        $this->defaultHeaders = [];

        return $this->withToken($token);
    }

    private function platformAdmin(): void
    {
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    public function test_an_administrator_asks_for_more_texts_and_the_platform_sees_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $now = app(Plans::class)->smsLimit($acme);

        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/billing/text-request', ['requested_limit' => $now + 400, 'message' => 'A campaign'])->assertCreated()->assertJsonPath('data.text_request.status', 'pending');

        $request = SeatRequest::first();
        $this->assertSame([SeatRequest::TEXTS, $now, $now + 400], [$request->kind, $request->current_limit, $request->requested_limit]);
        $this->platformAdmin();
        $this->get('/platform/seat-requests')->assertOk()->assertInertia(fn ($p) => $p->where('requests.0.kind', 'texts')->where('requests.0.limit_now', $now));
    }

    public function test_the_request_must_ask_for_more_than_the_company_has_and_only_one_waits_at_a_time(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $now = app(Plans::class)->smsLimit($acme);
        $api = fn () => $this->api('owner@acme.test', 'acme');

        $api()->postJson('/api/v1/billing/text-request', ['requested_limit' => $now])->assertUnprocessable();
        $api()->postJson('/api/v1/billing/text-request', ['requested_limit' => $now + 100])->assertCreated();
        $api()->postJson('/api/v1/billing/text-request', ['requested_limit' => $now + 200])->assertUnprocessable();
        $this->assertSame(1, SeatRequest::count());
    }

    public function test_only_an_administrator_can_ask(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->run(fn () => app(TenantProvisioner::class)->addMember(Tenant::first(), app(TenantProvisioner::class)->identity('Member', 'member@acme.test', 'Member-Passw0rd-1'), 'user'));

        $this->api('member@acme.test', 'acme', 'Member-Passw0rd-1')->postJson('/api/v1/billing/text-request', ['requested_limit' => 99999])->assertForbidden();
    }

    public function test_approving_raises_the_companys_texts_by_the_difference_and_declining_changes_nothing(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $base = $plans->tier((int) $acme->tier)['sms_per_month'];
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/billing/text-request', ['requested_limit' => $base + 300])->assertCreated();
        $this->platformAdmin();

        $this->post('/platform/seat-requests/'.SeatRequest::first()->id.'/approve', ['granted_limit' => $base + 250])->assertSessionHasNoErrors();

        $acme->refresh();
        $this->assertSame(250, (int) $acme->extra_sms);
        $this->assertSame($base + 250, $plans->smsLimit($acme));
        $this->assertSame('approved', SeatRequest::first()->status);

        $second = $this->api('owner@acme.test', 'acme');
        $second->postJson('/api/v1/billing/text-request', ['requested_limit' => $base + 900])->assertCreated();
        $this->platformAdmin();
        $this->post('/platform/seat-requests/'.SeatRequest::latest('id')->first()->id.'/decline', ['response' => 'Not now'])->assertSessionHasNoErrors();
        $this->assertSame(250, (int) $acme->fresh()->extra_sms);
    }

    public function test_the_answer_shows_on_the_billing_page_until_the_company_clears_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $base = app(Plans::class)->smsLimit($acme);
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/billing/text-request', ['requested_limit' => $base + 100]);
        $this->platformAdmin();
        $this->post('/platform/seat-requests/'.SeatRequest::first()->id.'/approve', []);

        $api = fn () => $this->api('owner@acme.test', 'acme');
        $id = $api()->getJson('/api/v1/billing')->assertJsonPath('data.text_request.status', 'approved')->json('data.text_request.id');
        $api()->postJson("/api/v1/limits/requests/{$id}/dismiss")->assertNoContent();
        $api()->getJson('/api/v1/billing')->assertJsonPath('data.text_request', null);
    }

    public function test_approving_for_this_month_only_gives_a_bonus_that_ends_with_the_month(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $base = $plans->smsLimit($acme);
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/billing/text-request', ['requested_limit' => $base + 300])->assertCreated();
        $this->platformAdmin();

        $this->post('/platform/seat-requests/'.SeatRequest::first()->id.'/approve', ['this_month_only' => true])->assertSessionHasNoErrors();

        $acme->refresh();
        $this->assertSame([0, 300], [(int) $acme->extra_sms, (int) $acme->bonus_sms]);
        $this->assertSame($base + 300, $plans->smsLimit($acme));
        $this->assertSame(now()->format('Y-m'), SeatRequest::first()->granted_month);
        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/billing')->assertJsonPath('data.extras.bonus_sms', 300)->assertJsonPath('data.text_request.granted_month', now()->format('Y-m'));

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());
        $this->assertSame($base, $plans->smsLimit($acme->fresh()), 'the bonus is gone with the month, nothing had to run');
    }

    public function test_a_permanent_approval_replaces_a_running_bonus_and_a_bonus_sits_on_top_of_the_permanent_extra(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $plans = app(Plans::class);
        $tier = $plans->tier((int) $acme->tier)['sms_per_month'];
        $acme->update(['extra_sms' => 100]);
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/billing/text-request', ['requested_limit' => $tier + 500])->assertCreated();
        $this->platformAdmin();
        $this->post('/platform/seat-requests/'.SeatRequest::first()->id.'/approve', ['this_month_only' => true]);
        $acme->refresh();
        $this->assertSame([100, 400], [(int) $acme->extra_sms, (int) $acme->bonus_sms], 'the bonus only covers what the permanent extra does not');

        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/billing/text-request', ['requested_limit' => $tier + 900])->assertCreated();
        $this->platformAdmin();
        $this->post('/platform/seat-requests/'.SeatRequest::latest('id')->first()->id.'/approve', []);
        $acme->refresh();
        $this->assertSame([900, 0], [(int) $acme->extra_sms, (int) $acme->bonus_sms]);
    }
}
