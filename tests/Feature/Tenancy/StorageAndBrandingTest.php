<?php

namespace Tests\Feature\Tenancy;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

class StorageAndBrandingTest extends TenantTestCase
{
    public function test_each_company_has_its_own_file_storage(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');

        $a->run(fn () => Storage::disk('local')->put('contacts/1/secret.txt', 'alpha only'));

        $b->run(function () {
            $this->assertFalse(Storage::disk('local')->exists('contacts/1/secret.txt'));
            Storage::disk('local')->put('contacts/1/secret.txt', 'beta only');
        });
        $a->run(fn () => $this->assertSame('alpha only', Storage::disk('local')->get('contacts/1/secret.txt')));

        $a->run(fn () => $this->assertStringContainsString("tenant{$a->id}", Storage::disk('local')->path('x')));
        $this->assertStringNotContainsString('tenant', Storage::disk('local')->path('x'), 'outside a company the normal storage root applies');
    }

    public function test_the_logo_is_per_company_and_served_by_a_route(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $login = fn ($email) => $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => 'Owner-Passw0rd-1'])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $logoUrl = $this->withToken($login('a@alpha.test'))->post('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 120, 120)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.logo_url');
        $this->assertStringContainsString('/branding/logo', $logoUrl);

        // Beta has no logo, Alpha has one; as a signed-in browser session of each company
        $profileOf = fn ($company) => $company->run(fn () => User::firstOrFail());
        $this->defaultHeaders = [];
        $this->app['auth']->forgetGuards();
        $this->withSession(['tenant_id' => $a->id])->actingAs($profileOf($a), 'web')->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->app['auth']->forgetGuards();
        $this->withSession(['tenant_id' => $b->id])->actingAs($profileOf($b), 'web')->get('/branding/logo')->assertNotFound();

        $a->run(fn () => $this->assertNotNull(Setting::get('app.logo_path')));
        $b->run(fn () => $this->assertNull(Setting::get('app.logo_path')));
    }

    public function test_the_sign_in_page_shows_the_platforms_branding_not_a_companys(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $a->run(function () {
            Setting::put('app.name', 'Alpha Secret Brand');
            Setting::put('app.tagline', 'Only for Alpha staff');
        });

        $this->get('/login')->assertOk()->assertDontSee('Alpha Secret Brand')->assertDontSee('Only for Alpha staff')->assertSee('Build. Connect. Automate.');
    }
}
