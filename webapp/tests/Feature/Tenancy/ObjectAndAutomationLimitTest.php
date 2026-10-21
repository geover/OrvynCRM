<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\ObjectAutomationLimit;
use App\Models\SeatRequest;
use App\Services\Tenancy\Pricing;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** How many data objects a company may have and how many automations each may have, asking for more, and the platform's side of it. */
class ObjectAndAutomationLimitTest extends TenantTestCase
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

    private function automation(string $name, int $objectId): array
    {
        return ['name' => $name, 'data_object_id' => $objectId, 'actions' => [['type' => 'add_tag', 'tag' => 'x']]];
    }

    public function test_a_new_company_gets_the_default_limits(): void
    {
        config(['tenancy.default_max_data_objects' => 10, 'tenancy.default_max_automations_per_object' => 5]);
        $acme = $this->company('Acme', 'owner@acme.test');

        $this->assertSame([10, 5], [$acme->max_data_objects, $acme->max_automations_per_object]);
    }

    public function test_data_objects_stop_at_the_limit(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $used = $api()->getJson('/api/v1/limits')->assertOk()->json('data.data_objects.used');
        $acme->update(['max_data_objects' => $used + 1]);

        $api()->postJson('/api/v1/data-objects', ['name' => 'Cases'])->assertCreated();
        $api()->postJson('/api/v1/data-objects', ['name' => 'Projects'])->assertUnprocessable()->assertJsonValidationErrors('name')
            ->assertJsonPath('errors.name.0', fn ($m) => str_contains($m, 'limit of '.($used + 1).' data objects'));
        $api()->getJson('/api/v1/limits')->assertJsonPath('data.data_objects.full', true)->assertJsonPath('data.data_objects.left', 0);
    }

    public function test_automations_are_limited_per_data_object(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_automations_per_object' => 1]);
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $one = $api()->postJson('/api/v1/data-objects', ['name' => 'Cases'])->assertCreated()->json('data.id');
        $two = $api()->postJson('/api/v1/data-objects', ['name' => 'Projects'])->assertCreated()->json('data.id');

        $api()->postJson('/api/v1/automations', $this->automation('First', $one))->assertCreated();
        $api()->postJson('/api/v1/automations', $this->automation('Second', $one))->assertUnprocessable()->assertJsonValidationErrors('data_object_id');
        $api()->postJson('/api/v1/automations', $this->automation('Elsewhere', $two))->assertCreated();                 // another object has its own allowance
        $api()->postJson('/api/v1/automations', ['name' => 'No object', 'actions' => [['type' => 'add_tag', 'tag' => 'x']]])->assertCreated();   // not tied to an object: not limited
    }

    public function test_moving_an_automation_onto_a_full_object_is_refused(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->update(['max_automations_per_object' => 1]);
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $one = $api()->postJson('/api/v1/data-objects', ['name' => 'Cases'])->json('data.id');
        $two = $api()->postJson('/api/v1/data-objects', ['name' => 'Projects'])->json('data.id');
        $api()->postJson('/api/v1/automations', $this->automation('First', $one))->assertCreated();
        $mover = $api()->postJson('/api/v1/automations', $this->automation('Mover', $two))->assertCreated()->json('data.id');

        $api()->putJson("/api/v1/automations/{$mover}", ['data_object_id' => $one])->assertUnprocessable();
    }

    public function test_asking_for_more_data_objects_sends_a_request_with_the_quote_and_one_waits_at_a_time(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();
        app(Pricing::class)->save(['price' => 10, 'data_object_price' => 5, 'automation_price' => 2, 'currency' => 'USD', 'period' => 'month']);
        $max = $acme->max_data_objects;
        $api = fn () => $this->api('owner@acme.test', 'acme');

        $api()->postJson('/api/v1/limits/request', ['kind' => 'data_objects', 'requested_limit' => $max])->assertUnprocessable();              // must be more than now
        $api()->postJson('/api/v1/limits/request', ['kind' => 'data_objects', 'requested_limit' => $max + 3, 'message' => 'Growing'])->assertCreated()
            ->assertJsonPath('data.pricing.data_object_price', 5)->assertJsonPath('data.requests.0.status', 'pending');
        $request = SeatRequest::firstOrFail();
        $this->assertSame(['data_objects', null, $max, $max + 3], [$request->kind, $request->data_object_id, $request->current_limit, $request->requested_limit]);
        $this->assertEquals(5, $request->price_per_user);

        $api()->postJson('/api/v1/limits/request', ['kind' => 'data_objects', 'requested_limit' => $max + 9])->assertUnprocessable();         // one at a time
    }

    public function test_asking_for_more_automations_names_the_data_object(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $one = $api()->postJson('/api/v1/data-objects', ['name' => 'Cases'])->json('data.id');
        $two = $api()->postJson('/api/v1/data-objects', ['name' => 'Projects'])->json('data.id');
        $default = $acme->max_automations_per_object;

        $api()->postJson('/api/v1/limits/request', ['kind' => 'automations', 'requested_limit' => $default + 2])->assertUnprocessable()->assertJsonValidationErrors('data_object_id');
        $api()->postJson('/api/v1/limits/request', ['kind' => 'automations', 'data_object_id' => $one, 'requested_limit' => $default + 2])->assertCreated();
        $api()->postJson('/api/v1/limits/request', ['kind' => 'automations', 'data_object_id' => $two, 'requested_limit' => $default + 2])->assertCreated();   // another object may ask too
        $api()->postJson('/api/v1/limits/request', ['kind' => 'automations', 'data_object_id' => $one, 'requested_limit' => $default + 3])->assertUnprocessable();

        $this->assertSame('Cases', SeatRequest::where('data_object_id', $one)->value('data_object_name'));
    }

    public function test_the_platform_approves_each_kind_and_only_that_limit_changes(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $object = $api()->postJson('/api/v1/data-objects', ['name' => 'Cases'])->json('data.id');
        $max = $acme->max_data_objects;
        $default = $acme->max_automations_per_object;
        $api()->postJson('/api/v1/limits/request', ['kind' => 'data_objects', 'requested_limit' => $max + 5])->assertCreated();
        $api()->postJson('/api/v1/limits/request', ['kind' => 'automations', 'data_object_id' => $object, 'requested_limit' => $default + 4])->assertCreated();
        $this->platformAdmin();

        foreach (SeatRequest::orderBy('id')->get() as $request) {
            $this->post("/platform/requests/{$request->id}/approve", [])->assertRedirect();
        }

        $this->assertSame($max + 5, $acme->fresh()->max_data_objects);
        $this->assertSame($default, $acme->fresh()->max_automations_per_object, 'the company-wide default is untouched');
        $this->assertSame($default + 4, ObjectAutomationLimit::where('tenant_id', $acme->getTenantKey())->where('data_object_id', $object)->value('max_automations'));
        $this->assertSame(['approved', 'approved'], SeatRequest::orderBy('id')->pluck('status')->all());

        $row = collect($this->api('owner@acme.test', 'acme')->getJson('/api/v1/limits')->json('data.objects'))->firstWhere('id', $object);
        $this->assertSame([$default + 4, true], [$row['max'], $row['custom']]);
    }

    public function test_the_platform_can_set_an_objects_allowance_directly_and_clear_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $object = $this->api('owner@acme.test', 'acme')->postJson('/api/v1/data-objects', ['name' => 'Cases'])->json('data.id');
        $this->platformAdmin();

        $this->put("/platform/companies/{$acme->getTenantKey()}/object-limits/{$object}", ['max_automations' => 9])->assertRedirect();
        $this->assertSame(9, ObjectAutomationLimit::where('data_object_id', $object)->value('max_automations'));
        $this->delete("/platform/companies/{$acme->getTenantKey()}/object-limits/{$object}")->assertRedirect();
        $this->assertSame(0, ObjectAutomationLimit::count());
        $this->put("/platform/companies/{$acme->getTenantKey()}/object-limits/99999", ['max_automations' => 9])->assertNotFound();
    }

    public function test_the_platform_sets_the_defaults_on_a_company(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->patch("/platform/companies/{$acme->getTenantKey()}", ['max_data_objects' => 25, 'max_automations_per_object' => 12])->assertRedirect();

        $this->assertSame([25, 12], [$acme->fresh()->max_data_objects, $acme->fresh()->max_automations_per_object]);
    }

    public function test_prices_for_objects_and_automations_are_saved_with_the_user_price(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/pricing', ['price' => 10, 'data_object_price' => 5, 'automation_price' => 2.5, 'currency' => 'EUR', 'period' => 'year'])->assertRedirect();

        $this->assertSame([10.0, 5.0, 2.5, 'EUR', 'year'], collect(app(Pricing::class)->current())->only(['price', 'data_object_price', 'automation_price', 'currency', 'period'])->values()->all());
    }

    public function test_only_administrators_see_or_ask_for_limits(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/users', ['name' => 'Plain', 'email' => 'plain@acme.test', 'password' => 'Person-Passw0rd-1', 'role' => 'user'])->assertCreated();

        $plain = $this->api('plain@acme.test', 'acme', 'Person-Passw0rd-1');
        $plain->getJson('/api/v1/limits')->assertForbidden();
        $plain->postJson('/api/v1/limits/request', ['kind' => 'data_objects', 'requested_limit' => $acme->max_data_objects + 1])->assertForbidden();
    }

    public function test_a_company_can_clear_the_outcome_of_a_handled_request_but_not_one_still_waiting(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $api()->postJson('/api/v1/limits/request', ['kind' => 'data_objects', 'requested_limit' => $acme->max_data_objects + 2])->assertCreated();
        $request = SeatRequest::firstOrFail();

        $api()->postJson("/api/v1/limits/requests/{$request->id}/dismiss")->assertUnprocessable();                         // still waiting: it stays visible
        $this->platformAdmin();
        $this->post("/platform/requests/{$request->id}/approve", [])->assertRedirect();

        $api()->getJson('/api/v1/limits')->assertJsonPath('data.requests.0.status', 'approved')->assertJsonPath('data.requests.0.id', $request->id);
        $api()->postJson("/api/v1/limits/requests/{$request->id}/dismiss")->assertNoContent();
        $api()->getJson('/api/v1/limits')->assertJsonCount(0, 'data.requests');                                              // gone for the company...
        $this->assertNotNull($request->fresh()->tenant_dismissed_at);
        $this->assertSame('approved', $request->fresh()->status);                                                           // ...but the platform keeps it
    }

    public function test_clearing_works_for_user_requests_too_and_only_for_the_companys_own(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $api = fn (string $email = 'owner@acme.test', string $company = 'acme') => $this->api($email, $company);
        $api()->postJson('/api/v1/seats/request', ['requested_limit' => $acme->max_users + 1])->assertCreated();
        $request = SeatRequest::firstOrFail();
        $this->platformAdmin();
        $this->post("/platform/requests/{$request->id}/decline", [])->assertRedirect();

        $api('owner@beta.test', 'beta')->postJson("/api/v1/limits/requests/{$request->id}/dismiss")->assertNotFound();      // another company's request
        $this->assertNull($request->fresh()->tenant_dismissed_at);

        $api()->getJson('/api/v1/seats')->assertJsonPath('data.request.status', 'declined');
        $api()->postJson("/api/v1/limits/requests/{$request->id}/dismiss")->assertNoContent();
        $api()->getJson('/api/v1/seats')->assertJsonPath('data.request', null);
    }
}
