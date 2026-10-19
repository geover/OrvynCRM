<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** A company's favicon is its icon in the platform's Companies list. */
class CompanyIconTest extends TenantTestCase
{
    private Tenant $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
    }

    private function asCompanyAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $this->postJson('/api/v1/auth/token', ['email' => 'admin@alpha.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'alpha'])->assertOk()->json('token');

        return $this->withToken($token);
    }

    private function listedIcon(): ?string
    {
        $this->defaultHeaders = [];
        $this->app['auth']->forgetGuards();
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
        $icon = null;
        $this->get('/platform')->assertOk()->assertInertia(function ($p) use (&$icon) {
            $icon = collect($p->toArray()['props']['companies'])->firstWhere('slug', 'alpha')['favicon_url'];
        });

        return $icon;
    }

    public function test_a_company_with_a_favicon_shows_it_as_its_icon_and_without_one_it_has_none(): void
    {
        $this->assertNull($this->listedIcon());                                       // nothing set yet

        $this->asCompanyAdmin()->post('/api/v1/settings/favicon', ['favicon' => UploadedFile::fake()->image('icon.png', 64, 64)], ['Accept' => 'application/json'])->assertOk();
        $icon = $this->listedIcon();
        $this->assertStringContainsString("/platform/companies/{$this->alpha->id}/favicon?v=", $icon);
        $this->get(strtok($icon, '?'))->assertOk()->assertHeader('Cache-Control');   // the platform can fetch it without opening the company

        $this->asCompanyAdmin()->deleteJson('/api/v1/settings/favicon')->assertOk();
        $this->assertNull($this->listedIcon());
        $this->get("/platform/companies/{$this->alpha->id}/favicon")->assertNotFound();
    }
}
