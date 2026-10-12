<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The sign-in pages look the same for everybody and only the platform changes them. */
class SignInBrandingTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/platform/branding'));
        PlatformSetting::forgetCache();
        parent::tearDown();
    }

    private function platformAdmin(): void
    {
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    public function test_a_companys_own_look_never_reaches_the_sign_in_pages(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->app['auth']->forgetGuards();
        $acme->run(function () {
            Setting::put('app.name', 'Acme Rocket CRM');
            Setting::put('app.tagline', 'Acme tagline');
            Setting::put('app.ui_colour', '#ff0000');
        });

        // a browser whose session still points at Acme (it just signed out of the app, or is switching company)
        foreach (['/login', '/forgot-password'] as $path) {
            $this->withSession(['tenant_id' => $acme->id])->get($path)->assertOk()->assertInertia(fn ($p) => $p
                ->where('app.name', config('app.name'))->where('app.tagline', 'Build. Connect. Automate.')->where('app.color', '#4f46e5'));
        }

        // inside the app the company's look still applies
        $this->flushSession();
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->where('app.name', 'Acme Rocket CRM')->where('app.color', '#ff0000'));
        $this->get('/choose-company')->assertOk()->assertInertia(fn ($p) => $p->where('app.name', config('app.name'))->where('app.color', '#4f46e5'));
    }

    public function test_the_platform_sets_the_sign_in_look_for_everyone(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->get('/platform/branding')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Branding')->where('branding.color', '#4f46e5'));
        $this->patch('/platform/branding', ['name' => 'Orvyn Cloud', 'tagline' => 'One CRM for all', 'color' => '#0891b2', 'theme' => 'light'])->assertRedirect()->assertSessionHas('success');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        foreach ([null, $acme->id] as $company) {
            $this->flushSession();
            $request = $company ? $this->withSession(['tenant_id' => $company]) : $this;
            $request->get('/login')->assertOk()->assertInertia(fn ($p) => $p->where('app.name', 'Orvyn Cloud')->where('app.tagline', 'One CRM for all')->where('app.color', '#0891b2'));
        }

        // a cleared tagline stays cleared
        $this->platformAdmin();
        $this->patch('/platform/branding', ['name' => 'Orvyn Cloud', 'tagline' => '', 'color' => '#0891b2', 'theme' => 'light']);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.tagline', ''));
    }

    public function test_validation_and_access(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get('/platform/branding')->assertForbidden();                                   // a company administrator
        $this->patch('/platform/branding', ['name' => 'Hijacked', 'color' => '#000000'])->assertForbidden();
        $this->post('/platform/branding/logo', ['logo' => UploadedFile::fake()->image('x.png')])->assertForbidden();
        $this->assertNull(PlatformSetting::get('login.name'));

        $this->platformAdmin();
        $this->patch('/platform/branding', ['name' => '', 'color' => 'red', 'theme' => 'purple'])->assertSessionHasErrors(['name', 'color', 'theme']);
        $this->patch('/platform/branding', ['name' => 'X', 'color' => '#12345', 'theme' => 'light'])->assertSessionHasErrors('color');
        $this->post('/platform/branding/logo', ['logo' => UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('logo');
    }

    public function test_the_logo_is_the_platforms_and_served_to_everyone_without_signing_in(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->get('/brand/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');     // the built-in logo until one is uploaded
        $this->platformAdmin();

        $this->post('/platform/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 120, 40)])->assertRedirect()->assertSessionHas('success');
        $path = PlatformSetting::get('login.logo_path');
        $this->assertTrue(Storage::disk('platform')->exists($path));
        $this->assertFalse($acme->run(fn () => Storage::disk('public')->exists($path)), "not in any company's storage");

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/brand/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.logo_url', fn ($url) => str_contains($url, '/brand/logo')));

        // a company logo of its own is separate and does not show on the sign-in page
        $acme->run(fn () => Setting::put('app.logo_path', 'branding/acme.png'));
        $this->withSession(['tenant_id' => $acme->id])->get('/login')->assertInertia(fn ($p) => $p->where('app.logo_url', fn ($url) => str_contains($url, '/brand/logo')));

        $this->platformAdmin();
        $this->delete('/platform/branding/logo')->assertRedirect();
        $this->assertNull(PlatformSetting::get('login.logo_path'));
        $this->assertFalse(Storage::disk('platform')->exists($path));
        $this->get('/brand/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');     // back to the built-in one
    }

    public function test_the_background_is_the_platforms_too_with_the_built_in_one_as_default(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.background_url', null));
        $this->get('/brand/background')->assertNotFound();

        $this->platformAdmin();
        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->image('office.jpg', 1600, 900)])->assertRedirect()->assertSessionHas('success');
        $path = PlatformSetting::get('login.background_path');
        $this->assertTrue(Storage::disk('platform')->exists($path));
        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->create('big.png', 6000, 'image/png')])->assertSessionHasErrors('background');
        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('background');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withSession(['tenant_id' => $acme->id])->get('/login')->assertInertia(fn ($p) => $p->where('app.background_url', fn ($url) => str_contains($url, '/brand/background')));
        $this->get('/brand/background')->assertOk();

        // companies cannot change it
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->image('hack.jpg')])->assertForbidden();
        $this->delete('/platform/branding/background')->assertForbidden();

        $this->platformAdmin();
        $this->delete('/platform/branding/background')->assertRedirect();
        $this->assertNull(PlatformSetting::get('login.background_path'));
        $this->assertFalse(Storage::disk('platform')->exists($path));
    }

    public function test_a_company_without_a_logo_uses_the_platform_logo_and_its_own_replaces_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $api = function () {
            $this->app['auth']->forgetGuards();
            $token = $this->postJson('/api/v1/auth/token', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'acme'])->json('token');
            $client = $this->withToken($token);
            $this->defaultHeaders = [];             // the token is for the API calls only

            return $client->withToken($token);
        };
        $web = fn () => $this->get('/');

        $web()->assertInertia(fn ($p) => $p->where('app.logo_url', fn ($u) => str_contains($u, '/brand/logo?v=default')));
        $api()->getJson('/api/v1/settings')->assertJsonPath('data.logo_is_default', true);

        // the platform changes the default: companies without their own logo follow at once
        PlatformSetting::put('login.logo_path', 'branding/platform-logo.png');
        $web()->assertInertia(fn ($p) => $p->where('app.logo_url', fn ($u) => str_contains($u, '/brand/logo') && ! str_contains($u, 'v=default')));

        // the company uploads its own: that one is shown
        $api()->postJson('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->image('acme.png')])->assertOk()
            ->assertJsonPath('data.logo_is_default', false)->assertJson(fn ($j) => $j->where('data.logo_url', fn ($u) => str_contains($u, '/branding/logo'))->etc());
        $web()->assertInertia(fn ($p) => $p->where('app.logo_url', fn ($u) => str_contains($u, '/branding/logo')));

        // removing it goes back to the platform's
        $api()->deleteJson('/api/v1/settings/logo')->assertOk()->assertJsonPath('data.logo_is_default', true)
            ->assertJson(fn ($j) => $j->where('data.logo_url', fn ($u) => str_contains($u, '/brand/logo'))->etc());
        $web()->assertInertia(fn ($p) => $p->where('app.logo_url', fn ($u) => str_contains($u, '/brand/logo')));
    }

    public function test_the_platform_sets_the_favicon_for_everyone(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->get('/login')->assertSee('<link rel="icon" href="'.url('/favicon.svg').'">', false);
        $this->get('/brand/favicon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->post('/platform/branding/favicon', ['favicon' => UploadedFile::fake()->image('i.png', 64, 64)])->assertForbidden();
        $this->assertNull(PlatformSetting::get('login.favicon_path'));

        $this->platformAdmin();
        $this->post('/platform/branding/favicon', ['favicon' => UploadedFile::fake()->create('i.svg', 2, 'image/svg+xml')])->assertSessionHasErrors('favicon');
        $this->post('/platform/branding/favicon', ['favicon' => UploadedFile::fake()->create('huge.png', 600, 'image/png')])->assertSessionHasErrors('favicon');
        $this->post('/platform/branding/favicon', ['favicon' => UploadedFile::fake()->image('i.png', 64, 64)])->assertRedirect()->assertSessionHas('success');
        $path = PlatformSetting::get('login.favicon_path');
        $this->assertTrue(Storage::disk('platform')->exists($path));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/login')->assertSee('<link rel="icon" href="'.url('/brand/favicon').'?v=', false);
        $this->get('/brand/favicon')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->platformAdmin();
        $this->delete('/platform/branding/favicon')->assertRedirect();
        $this->assertFalse(Storage::disk('platform')->exists($path));
        $this->get('/brand/favicon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_a_company_favicon_replaces_the_platform_one_only_for_that_company(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $api = function (string $email, string $company) {
            $this->app['auth']->forgetGuards();
            $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => 'Owner-Passw0rd-1', 'company' => $company])->json('token');
            $client = $this->withToken($token);
            $this->defaultHeaders = [];             // the token is for the API calls only

            return $client->withToken($token);
        };
        $platformIcon = url('/favicon.svg');

        // no favicon of its own: the platform's, in the page head and in the shared props
        $this->get('/')->assertSee('<link rel="icon" href="'.$platformIcon.'">', false)->assertInertia(fn ($p) => $p->where('app.favicon_url', $platformIcon));
        $api('owner@acme.test', 'acme')->getJson('/api/v1/settings')->assertJsonPath('data.favicon_is_default', true);

        // the platform changes ITS favicon: companies without their own follow at once
        PlatformSetting::put('login.favicon_path', 'branding/platform-icon.png');
        $this->get('/')->assertInertia(fn ($p) => $p->where('app.favicon_url', fn ($u) => str_contains($u, '/brand/favicon')));

        // Acme uploads its own
        $client = $api('owner@acme.test', 'acme');
        $client->postJson('/api/v1/settings/favicon', ['favicon' => UploadedFile::fake()->create('i.svg', 2, 'image/svg+xml')])->assertUnprocessable()->assertJsonValidationErrors('favicon');
        $client->postJson('/api/v1/settings/favicon', ['favicon' => UploadedFile::fake()->create('huge.png', 600, 'image/png')])->assertUnprocessable();
        $client->postJson('/api/v1/settings/favicon', ['favicon' => UploadedFile::fake()->image('acme.png', 64, 64)])->assertOk()
            ->assertJsonPath('data.favicon_is_default', false)->assertJson(fn ($j) => $j->where('data.favicon_url', fn ($u) => str_contains($u, '/branding/favicon'))->etc());
        $this->get('/')->assertSee('/branding/favicon?v=', false)->assertInertia(fn ($p) => $p->where('app.favicon_url', fn ($u) => str_contains($u, '/branding/favicon')));
        $this->get('/branding/favicon')->assertOk()->assertHeader('Content-Type', 'image/png');

        // the sign-in pages and the platform area never take a company's favicon
        $this->flushSession();
        $this->defaultHeaders = [];
        $this->app['auth']->forgetGuards();
        $this->withSession(['tenant_id' => $acme->id])->get('/login')->assertInertia(fn ($p) => $p->where('app.favicon_url', fn ($u) => str_contains($u, '/brand/favicon')));

        // another company is unaffected, and a regular user cannot change it
        $api('owner@beta.test', 'beta')->getJson('/api/v1/settings')->assertJsonPath('data.favicon_is_default', true);
        $api('owner@acme.test', 'acme')->postJson('/api/v1/users', ['name' => 'Rae', 'email' => 'rae@acme.test', 'password' => 'Regular-Passw0rd-1', 'role' => 'user'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $rae = $this->postJson('/api/v1/auth/token', ['email' => 'rae@acme.test', 'password' => 'Regular-Passw0rd-1', 'company' => 'acme'])->json('token');
        $this->defaultHeaders = [];
        $raeClient = $this->withToken($rae);
        $this->defaultHeaders = [];
        $raeClient->withToken($rae)->postJson('/api/v1/settings/favicon', ['favicon' => UploadedFile::fake()->image('x.png')])->assertForbidden();

        // removing it goes back to the platform's
        $api('owner@acme.test', 'acme')->deleteJson('/api/v1/settings/favicon')->assertOk()->assertJsonPath('data.favicon_is_default', true)
            ->assertJson(fn ($j) => $j->where('data.favicon_url', fn ($u) => str_contains($u, '/brand/favicon'))->etc());
    }

    public function test_a_company_can_choose_its_own_sidebar_colour_or_follow_the_theme(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $client = function (string $email, string $company) {
            $this->app['auth']->forgetGuards();
            $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => 'Owner-Passw0rd-1', 'company' => $company])->json('token');
            $api = $this->withToken($token);
            $this->defaultHeaders = [];

            return $api->withToken($token);
        };

        $client('owner@acme.test', 'acme')->getJson('/api/v1/settings')->assertJsonPath('data.sidebar_color', null);          // follows the theme colour
        $client('owner@acme.test', 'acme')->putJson('/api/v1/settings', ['sidebar_color' => '#F8FAFC'])->assertOk()->assertJsonPath('data.sidebar_color', '#f8fafc');
        $client('owner@acme.test', 'acme')->putJson('/api/v1/settings', ['sidebar_color' => 'blue'])->assertUnprocessable()->assertJsonValidationErrors('sidebar_color');
        $client('owner@beta.test', 'beta')->getJson('/api/v1/settings')->assertJsonPath('data.sidebar_color', null);          // per company

        $client('owner@acme.test', 'acme')->putJson('/api/v1/settings', ['color' => '#0891b2', 'sidebar_color' => null])->assertOk()
            ->assertJsonPath('data.sidebar_color', null)->assertJsonPath('data.color', '#0891b2');                          // unticking clears it

        // the sign-in pages and the platform area never carry a company's sidebar colour
        $client('owner@acme.test', 'acme')->putJson('/api/v1/settings', ['sidebar_color' => '#7f1d1d']);
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.sidebar_color', null));
    }
}
