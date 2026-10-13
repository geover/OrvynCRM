<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\SeatRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** How many people a company may have, asking for more, and the platform's side of it. */
class SeatLimitTest extends TenantTestCase
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

    private function person(string $n): array
    {
        return ['name' => "Person {$n}", 'email' => "p{$n}@acme.test", 'password' => 'Person-Passw0rd-1', 'role' => 'user'];
    }

    public function test_a_new_company_gets_the_default_limit_or_the_one_it_was_created_with(): void
    {
        config(['tenancy.default_max_users' => 5]);
        $this->assertSame(5, $this->company('Acme', 'owner@acme.test')->max_users);

        $custom = app(TenantProvisioner::class)->createCompany('Big', null, ['name' => 'Big Owner', 'email' => 'o@big.test', 'password' => 'Owner-Passw0rd-1'], maxUsers: 12);
        $this->assertSame(12, $custom->fresh()->max_users);
    }

    public function test_adding_people_stops_at_the_limit_and_suspending_frees_a_seat(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 3]);                                       // the owner is 1 of 3

        $api = fn () => $this->api('owner@acme.test', 'acme');
        $api()->postJson('/api/v1/users', $this->person('1'))->assertCreated();
        $second = $api()->postJson('/api/v1/users', $this->person('2'))->assertCreated()->json('data.id');
        $api()->postJson('/api/v1/users', $this->person('3'))->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'limit of 3 users'));
        $api()->getJson('/api/v1/seats')->assertOk()->assertJsonPath('data.used', 3)->assertJsonPath('data.max', 3)->assertJsonPath('data.full', true)->assertJsonPath('data.left', 0);

        $api()->patchJson("/api/v1/users/{$second}", ['status' => 'suspended'])->assertOk();                  // frees the seat
        $api()->getJson('/api/v1/seats')->assertJsonPath('data.used', 2)->assertJsonPath('data.full', false);
        $api()->postJson('/api/v1/users', $this->person('3'))->assertCreated();                                 // someone else takes it
        $api()->patchJson("/api/v1/users/{$second}", ['status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('status');   // so reactivating needs another

        $api()->deleteJson("/api/v1/users/{$second}")->assertNoContent();
        $api()->getJson('/api/v1/seats')->assertJsonPath('data.used', 3);                                     // it was suspended, so it never counted
        $this->assertSame(3, app(Seats::class)->usedFor($acme));
    }

    public function test_platform_support_profiles_never_use_a_seat(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 1]);
        $platform = AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        app(TenantProvisioner::class)->addSupportProfile($acme, $platform);

        $this->assertSame(1, app(Seats::class)->usedFor($acme));
        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/seats')->assertJsonPath('data.used', 1);
        $acme->run(fn () => $this->assertSame(1, User::listed()->count()));
    }

    public function test_only_administrators_see_or_request_seats(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 5]);
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/users', $this->person('1'))->assertCreated();

        $rae = $this->api('p1@acme.test', 'acme', 'Person-Passw0rd-1');
        $rae->getJson('/api/v1/seats')->assertForbidden();
        $rae->postJson('/api/v1/seats/request', ['requested_limit' => 9])->assertForbidden();
        $this->assertSame(0, SeatRequest::count());
    }

    public function test_a_company_can_request_more_once_at_a_time_and_the_platform_is_told(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 2]);
        $this->platformAdmin();
        $this->get('/platform/dashboard')->assertInertia(fn ($p) => $p->where('seatRequestsPending', 0));

        $owner = fn () => $this->api('owner@acme.test', 'acme');
        $owner()->postJson('/api/v1/seats/request', ['requested_limit' => 2])->assertUnprocessable();            // must ask for more than it has
        $owner()->postJson('/api/v1/seats/request', ['requested_limit' => 9, 'message' => 'We are hiring'])->assertCreated()
            ->assertJsonPath('data.request.status', 'pending')->assertJsonPath('data.request.requested_limit', 9);
        $owner()->postJson('/api/v1/seats/request', ['requested_limit' => 12])->assertUnprocessable();            // one waiting request is enough

        $request = SeatRequest::firstOrFail();
        $this->assertSame([$acme->id, 2, 9, 'pending'], [$request->tenant_id, $request->current_limit, $request->requested_limit, $request->status]);

        $this->platformAdmin();                                                                                    // the platform's menu shows it
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('seatRequestsPending', 1)->where('companies.0.pending_requests', 1));
        $this->get('/platform/seat-requests')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/SeatRequests')->has('requests', 1)->where('requests.0.company', 'Acme')->where('requests.0.status', 'pending'));
    }

    public function test_the_platform_approves_with_its_own_number_or_declines(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $acme->update(['max_users' => 2]);
        $beta->update(['max_users' => 2]);
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/seats/request', ['requested_limit' => 9])->assertCreated();
        $this->api('owner@beta.test', 'beta')->postJson('/api/v1/seats/request', ['requested_limit' => 7])->assertCreated();
        [$first, $second] = [SeatRequest::where('tenant_id', $acme->id)->first(), SeatRequest::where('tenant_id', $beta->id)->first()];

        $this->platformAdmin();
        $this->post("/platform/seat-requests/{$first->id}/approve", ['granted_limit' => 6, 'response' => 'Six for now'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(6, $acme->fresh()->max_users);
        $this->assertSame(['approved', 6, 'Six for now'], [$first->fresh()->status, $first->fresh()->granted_limit, $first->fresh()->response]);

        $this->post("/platform/seat-requests/{$second->id}/decline", ['response' => 'Please talk to sales'])->assertRedirect();
        $this->assertSame(2, $beta->fresh()->max_users);
        $this->assertSame('declined', $second->fresh()->status);

        $this->post("/platform/seat-requests/{$first->id}/decline")->assertUnprocessable();                       // handled once
        $this->get('/platform/seat-requests')->assertInertia(fn ($p) => $p->where('seatRequestsPending', 0));

        // the company sees the outcome and can add people again
        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/seats')->assertJsonPath('data.max', 6)->assertJsonPath('data.request.status', 'approved')->assertJsonPath('data.request.response', 'Six for now');
        $this->api('owner@beta.test', 'beta')->getJson('/api/v1/seats')->assertJsonPath('data.request.status', 'declined');
    }

    public function test_company_admins_cannot_reach_the_platform_side(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $request = SeatRequest::create(['tenant_id' => $acme->id, 'requester_name' => 'x', 'requester_email' => 'x@x.test', 'current_limit' => 2, 'requested_limit' => 9]);
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->get('/platform/seat-requests')->assertForbidden();
        $this->post("/platform/seat-requests/{$request->id}/approve")->assertForbidden();
        $this->get("/platform/companies/{$acme->id}/users")->assertForbidden();
        $this->post("/platform/companies/{$acme->id}/users", $this->person('1'))->assertForbidden();
        $this->patch("/platform/companies/{$acme->id}", ['max_users' => 999])->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_the_platform_sets_the_limit_and_adds_people_within_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 2]);
        $this->platformAdmin();

        $this->patch("/platform/companies/{$acme->id}", ['max_users' => 0])->assertSessionHasErrors('max_users');
        $this->patch("/platform/companies/{$acme->id}", ['max_users' => 'many'])->assertSessionHasErrors('max_users');

        $this->get("/platform/companies/{$acme->id}/users")->assertOk()->assertInertia(fn ($p) => $p->component('Platform/CompanyUsers')
            ->where('used', 1)->where('company.max_users', 2)->has('users', 1)->where('users.0.email', 'owner@acme.test'));

        $this->post("/platform/companies/{$acme->id}/users", array_merge($this->person('1'), ['role' => 'manager']))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, app(Seats::class)->usedFor($acme));
        $acme->run(fn () => $this->assertSame('manager', User::where('email', 'p1@acme.test')->value('role')));

        $this->post("/platform/companies/{$acme->id}/users", $this->person('2'))->assertSessionHasErrors('email');   // at the limit: raise it first
        $this->assertSame(2, app(Seats::class)->usedFor($acme));

        $this->patch("/platform/companies/{$acme->id}", ['max_users' => 6])->assertRedirect();                 // Tier 1 includes 5: the limit may go above that
        $this->post("/platform/companies/{$acme->id}/users", $this->person('2'))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(3, app(Seats::class)->usedFor($acme));

        $this->post("/platform/companies/{$acme->id}/users", $this->person('2'))->assertStatus(422);              // already in the company
        $this->post("/platform/companies/{$acme->id}/users", ['name' => 'Platform Admin', 'email' => 'platform@x.test', 'role' => 'admin'])->assertSessionHasErrors('email');
        $this->assertSame(3, Tenant::find($acme->id)->run(fn () => User::listed()->count()));
    }

    public function test_the_dashboard_shows_user_numbers_and_who_is_at_the_limit(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $acme->update(['max_users' => 1]);
        $beta->update(['max_users' => 10]);
        SeatRequest::create(['tenant_id' => $acme->id, 'requester_name' => 'x', 'requester_email' => 'x@x.test', 'current_limit' => 1, 'requested_limit' => 5]);
        $this->platformAdmin();
        $this->post('/platform/dashboard/refresh')->assertRedirect();                       // collects every company's totals (sync queue)

        $this->get('/platform/dashboard')->assertOk()->assertInertia(fn ($p) => $p
            ->where('kpis.users', 2)->where('kpis.seats', 11)->where('kpis.companies_full', 1)->where('kpis.seat_requests', 1)
            ->where('companies', fn ($companies) => collect($companies)->firstWhere('name', 'Acme')['max_users'] === 1 && collect($companies)->firstWhere('name', 'Acme')['users'] === 1));
    }

    public function test_the_blast_picker_never_lists_platform_support_profiles(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $platform = AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        app(TenantProvisioner::class)->addSupportProfile($acme, $platform);

        $names = $this->api('owner@acme.test', 'acme')->getJson('/api/v1/messages/blast-users')->assertOk()->json('data.*.name');
        $this->assertSame(['Acme Owner'], $names);
    }

    public function test_a_confirmation_that_does_not_match_is_refused_but_callers_without_one_still_work(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 9]);
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $person = $this->person('1');

        $api()->postJson('/api/v1/users', $person + ['password_confirmation' => 'Different-Passw0rd-2'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $api()->postJson('/api/v1/users', $person + ['password_confirmation' => $person['password']])->assertCreated();
        $api()->postJson('/api/v1/users', $this->person('2'))->assertCreated();                       // an integration that sends only a password

        $this->platformAdmin();
        $this->post("/platform/companies/{$acme->id}/users", $this->person('3') + ['password_confirmation' => 'nope-nope-nope'])->assertSessionHasErrors('password');
        $this->post("/platform/companies/{$acme->id}/users", $this->person('3') + ['password_confirmation' => 'Person-Passw0rd-1'])->assertRedirect()->assertSessionHas('success');
    }

    public function test_handled_requests_can_be_archived_restored_and_deleted_for_good(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 2]);
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/seats/request', ['requested_limit' => 6])->assertCreated();
        $request = SeatRequest::firstOrFail();
        $this->platformAdmin();

        $this->post("/platform/seat-requests/{$request->id}/archive")->assertUnprocessable();               // a waiting request must be handled first
        $this->delete("/platform/seat-requests/{$request->id}")->assertUnprocessable();
        $this->post("/platform/seat-requests/{$request->id}/approve", ['granted_limit' => 4])->assertRedirect();

        $this->post("/platform/seat-requests/{$request->id}/archive")->assertRedirect()->assertSessionHas('success', 'Archived.');
        $this->get('/platform/seat-requests')->assertInertia(fn ($p) => $p->has('requests', 1)->where('requests.0.archived_at', fn ($v) => $v !== null));
        $this->assertSame(4, $acme->fresh()->max_users, 'archiving does not undo what was granted');

        $this->post("/platform/seat-requests/{$request->id}/unarchive")->assertRedirect();
        $this->assertNull($request->fresh()->archived_at);
        $this->delete("/platform/seat-requests/{$request->id}")->assertUnprocessable();                     // not archived: kept

        $this->post("/platform/seat-requests/{$request->id}/archive");
        $this->delete("/platform/seat-requests/{$request->id}")->assertRedirect()->assertSessionHas('success', 'Deleted.');
        $this->assertNull(SeatRequest::find($request->id));
    }

    public function test_company_admins_cannot_archive_or_delete_requests(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $request = SeatRequest::create(['tenant_id' => $acme->id, 'requester_name' => 'x', 'requester_email' => 'x@x.test', 'current_limit' => 2, 'requested_limit' => 9, 'status' => 'declined']);
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->post("/platform/seat-requests/{$request->id}/archive")->assertForbidden();
        $this->delete("/platform/seat-requests/{$request->id}")->assertForbidden();
        $this->assertNull($request->fresh()->archived_at);
    }

    public function test_the_platform_sets_the_price_of_an_additional_user_and_companies_see_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 2]);

        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/seats')->assertJsonPath('data.pricing.price', null)->assertJsonPath('data.pricing.currency', 'USD');

        $this->platformAdmin();
        $this->get('/platform/pricing')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Pricing')->where('pricing.price', null)->has('currencies')->where('periods', ['month', 'year']));
        $this->patch('/platform/pricing', ['price' => 12.5, 'currency' => 'EUR', 'period' => 'month', 'note' => 'Taxes may apply'])->assertRedirect()->assertSessionHas('success', 'Pricing saved.');

        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/seats')
            ->assertJsonPath('data.pricing.price', 12.5)->assertJsonPath('data.pricing.currency', 'EUR')->assertJsonPath('data.pricing.period', 'month')->assertJsonPath('data.pricing.note', 'Taxes may apply');

        // the quote is kept with the request, so the platform reviews the figure the company saw
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/seats/request', ['requested_limit' => 6])->assertCreated()->assertJsonPath('data.pricing.price', 12.5);
        $request = SeatRequest::firstOrFail();
        $this->assertSame([12.5, 'EUR', 'month'], [(float) $request->price_per_user, $request->price_currency, $request->price_period]);

        $this->platformAdmin();
        $this->patch('/platform/pricing', ['price' => 99, 'currency' => 'USD', 'period' => 'year'])->assertRedirect();                 // changing the price later...
        $this->get('/platform/seat-requests')->assertInertia(fn ($p) => $p->where('requests.0.price_per_user', 12.5)->where('requests.0.price_currency', 'EUR'));   // ...does not rewrite the quote
    }

    public function test_pricing_can_be_cleared_and_is_validated_and_platform_only(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_users' => 5]);                                                  // the test environment's default is very high
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get('/platform/pricing')->assertForbidden();
        $this->patch('/platform/pricing', ['price' => 1, 'currency' => 'USD', 'period' => 'month'])->assertForbidden();

        $this->platformAdmin();
        $this->patch('/platform/pricing', ['price' => -3, 'currency' => 'USD', 'period' => 'month'])->assertSessionHasErrors('price');
        $this->patch('/platform/pricing', ['price' => 'lots', 'currency' => 'USD', 'period' => 'month'])->assertSessionHasErrors('price');
        $this->patch('/platform/pricing', ['price' => 5, 'currency' => 'XYZ', 'period' => 'month'])->assertSessionHasErrors('currency');
        $this->patch('/platform/pricing', ['price' => 5, 'currency' => 'USD', 'period' => 'week'])->assertSessionHasErrors('period');

        $this->patch('/platform/pricing', ['price' => 8, 'currency' => 'GBP', 'period' => 'year'])->assertRedirect();
        $this->patch('/platform/pricing', ['price' => '', 'currency' => 'GBP', 'period' => 'year'])->assertRedirect()->assertSessionHas('success', 'Saved. No price is shown to companies.');
        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/seats')->assertJsonPath('data.pricing.price', null);

        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/seats/request', ['requested_limit' => 9])->assertCreated();
        $this->assertNull(SeatRequest::firstOrFail()->price_per_user);                                    // no price: nothing quoted
        $acme->update(['max_users' => 5]);
    }
}
