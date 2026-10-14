<?php

namespace Tests\Feature\Tenancy;

use App\Integrations\Keap\KeapClient;
use App\Jobs\SyncKeapContacts;
use App\Models\AccountUser;
use App\Models\Integration;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** Keap: the company provides its details, and only then can the platform switch the sync on for it. */
class KeapSettingsTest extends TenantTestCase
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

    public function test_keap_sync_is_off_and_cannot_be_switched_on_until_the_company_saves_its_details(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $services = app(CompanyServices::class);
        $this->platformAdmin();

        $state = $services->states($acme)['keap_sync'];
        $this->assertSame([false, false], [$state['enabled'], $state['ready']]);
        $this->assertNotNull($state['note']);
        $this->assertTrue($services->states($acme)['messages']['enabled'], 'the other services are unaffected');

        $this->patch('/platform/services', ['company' => $acme->id, 'service' => 'keap_sync', 'enabled' => true])->assertStatus(422);
        $this->patch('/platform/services', ['company' => '*', 'service' => '*', 'enabled' => true])->assertRedirect();                 // "resume all" skips it
        $this->assertFalse($services->states($acme)['keap_sync']['enabled']);
    }

    public function test_saving_the_details_makes_the_switch_available_and_the_platform_then_turns_it_on(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $services = app(CompanyServices::class);

        $this->api('owner@acme.test', 'acme')->putJson('/api/v1/integrations/keap', ['api_key' => 'sk-test-1234567890'])->assertOk()
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.key_hint', '••••7890')->assertJsonPath('data.ready', true)->assertJsonPath('data.platform_on', false)
            ->assertJsonMissingPath('data.api_key');

        $acme->run(function () {
            $row = Integration::where('provider', 'keap')->firstOrFail();
            $this->assertSame('sk-test-1234567890', $row->credentials['api_key']);
            $this->assertStringNotContainsString('sk-test-1234567890', (string) $row->getRawOriginal('credentials'), 'encrypted at rest');
        });
        $this->assertTrue($services->states($acme)['keap_sync']['ready']);
        $this->assertFalse($services->states($acme)['keap_sync']['enabled'], 'saving the details does not switch it on: the platform does');

        $this->platformAdmin();
        $this->patch('/platform/services', ['company' => $acme->id, 'service' => 'keap_sync', 'enabled' => true])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($acme->run(fn () => app(CompanyServices::class)->enabled('keap_sync')));
    }

    public function test_removing_the_details_switches_it_off_again(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $services = app(CompanyServices::class);
        $this->api('owner@acme.test', 'acme')->putJson('/api/v1/integrations/keap', ['api_key' => 'sk-test-1234567890'])->assertOk();
        $services->set($acme, 'keap_sync', true);

        $this->api('owner@acme.test', 'acme')->deleteJson('/api/v1/integrations/keap')->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.ready', false);

        $this->assertSame([false, false], [$services->states($acme)['keap_sync']['enabled'], $services->states($acme)['keap_sync']['ready']]);
        $acme->run(fn () => $this->assertNull(Integration::where('provider', 'keap')->first()));
    }

    public function test_only_administrators_save_details_and_a_key_must_look_like_one(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/users', ['name' => 'Plain', 'email' => 'plain@acme.test', 'password' => 'Person-Passw0rd-1', 'role' => 'user'])->assertCreated();

        $this->api('plain@acme.test', 'acme', 'Person-Passw0rd-1')->putJson('/api/v1/integrations/keap', ['api_key' => 'sk-test-1234567890'])->assertForbidden();
        $this->api('owner@acme.test', 'acme')->putJson('/api/v1/integrations/keap', ['api_key' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('api_key');
    }

    public function test_the_key_is_tested_against_keap_and_keaps_own_refusal_is_shown(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $api()->postJson('/api/v1/integrations/keap/test')->assertUnprocessable();                              // nothing saved yet

        $api()->putJson('/api/v1/integrations/keap', ['api_key' => 'sk-test-1234567890'])->assertOk();
        $this->fakeHttp(['api.infusionsoft.com/*' => Http::response(['message' => 'Invalid API key'], 401)]);
        $api()->postJson('/api/v1/integrations/keap/test')->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Keap did not accept'));

        $this->fakeHttp(['api.infusionsoft.com/*' => Http::response(['tags' => []], 200)]);
        $api()->postJson('/api/v1/integrations/keap/test')->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Keap-API-Key', 'sk-test-1234567890'));                      // the key is sent as Keap expects it
    }

    public function test_sync_now_waits_for_the_platform_and_then_queues_the_job(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        Bus::fake();                                     // after the company exists: its database is made by queued jobs
        $api = fn () => $this->api('owner@acme.test', 'acme');
        $api()->putJson('/api/v1/integrations/keap', ['api_key' => 'sk-test-1234567890'])->assertOk();

        $api()->postJson('/api/v1/integrations/keap/sync')->assertUnprocessable();                              // not switched on yet
        app(CompanyServices::class)->set($acme, 'keap_sync', true);
        $api()->postJson('/api/v1/integrations/keap/sync')->assertStatus(202);
        Bus::assertDispatched(SyncKeapContacts::class);
    }

    public function test_the_client_still_uses_an_oauth_token_from_an_old_import(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->fakeHttp(['api.infusionsoft.com/*' => Http::response(['tags' => []], 200)]);

        $acme->run(function () {
            Integration::create(['provider' => 'keap', 'credentials' => ['access_token' => 'oauth-token'], 'is_enabled' => true]);
            app(KeapClient::class)->ping();
        });

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer oauth-token'));
    }
}
