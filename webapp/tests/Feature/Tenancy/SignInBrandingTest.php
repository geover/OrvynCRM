<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Support\Tenancy\Company;
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
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.background_is_default', true)->where('app.background_type', 'video')->where('app.background_url', fn ($u) => str_contains($u, '/brand/background?v=default')));
        $this->get('/brand/background')->assertOk()->assertHeader('Content-Type', 'video/mp4');       // the built-in looping video

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
        $this->get('/login')->assertSee('<link rel="icon" href="'.url('/favicon.svg').'?v=', false);        // the built-in file, with a fingerprint
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
        $platformIcon = Company::platformBranding()['favicon_url'];

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

    public function test_the_platform_chooses_the_dark_style_of_the_sign_in_pages(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.dark_style', 'navy'));                  // the default

        $this->platformAdmin();
        $this->patch('/platform/branding', ['name' => 'Orvyn', 'color' => '#4f46e5', 'theme' => 'dark', 'dark_style' => 'midnight'])->assertRedirect()->assertSessionHas('success');
        $this->patch('/platform/branding', ['name' => 'Orvyn', 'color' => '#4f46e5', 'theme' => 'dark', 'dark_style' => 'neon'])->assertSessionHasErrors('dark_style');
        $this->patch('/platform/branding', ['name' => 'Orvyn', 'color' => '#4f46e5', 'theme' => 'dark'])->assertRedirect();   // not sent: the choice is kept

        $this->flushSession();
        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->where('app.theme', 'dark')->where('app.dark_style', 'midnight'))
            ->assertSee('"midnight"', false);                                                                  // the first paint uses it too, so there is no flash
    }

    public function test_an_svg_logo_and_favicon_are_accepted_but_cleaned_and_served_as_a_harmless_image(): void
    {
        $this->platformAdmin();
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(1)</script><foreignObject><div/></foreignObject><rect width="5" height="5" fill="#4a6f9a" onclick="x()"/><use href="http://evil.test/x.svg#a"/></svg>';

        $this->post('/platform/branding/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg)])->assertSessionHasNoErrors();
        $stored = Storage::disk('platform')->get(PlatformSetting::get('login.logo_path'));
        $this->assertStringEndsWith('.svg', PlatformSetting::get('login.logo_path'));
        $this->assertStringContainsString('<rect', $stored);
        foreach (['<script', 'onload', 'onclick', 'foreignObject', 'evil.test'] as $gone) {
            $this->assertStringNotContainsString($gone, $stored);
        }

        $this->get('/brand/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");

        $this->post('/platform/branding/favicon', ['favicon' => UploadedFile::fake()->createWithContent('i.svg', $svg)])->assertSessionHasNoErrors();
        $this->get('/brand/favicon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        $this->post('/platform/branding/logo', ['logo' => UploadedFile::fake()->createWithContent('bad.svg', '<html><body>not a picture</body></html>')])->assertSessionHasErrors('logo');
        $this->assertNotNull(PlatformSetting::get('login.logo_path'), 'a refused file leaves the current logo alone');
    }

    public function test_the_sign_in_background_can_be_a_short_video(): void
    {
        $this->platformAdmin();

        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->create('clip.mov', 500, 'video/quicktime')])->assertSessionHasErrors('background');     // only MP4 and WebM
        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->create('big.mp4', 26000, 'video/mp4')])->assertSessionHasErrors('background');          // over 25 MB
        $this->assertNull(PlatformSetting::get('login.background_path'));

        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->create('clip.mp4', 800, 'video/mp4')])->assertSessionHasNoErrors()->assertSessionHas('success', 'Background video updated.');
        $this->assertStringEndsWith('.mp4', PlatformSetting::get('login.background_path'));

        $this->flushSession();
        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->where('app.background_type', 'video')->whereNot('app.background_url', null));
        $this->get('/brand/background')->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('Accept-Ranges', 'bytes');      // the browser can ask for parts of it

        $this->platformAdmin();
        $this->post('/platform/branding/background', ['background' => UploadedFile::fake()->image('bg.png', 1600, 900)])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.background_type', 'image'));                                          // a picture replaces the video
    }

    public function test_the_logo_can_be_put_on_a_white_plate_to_stay_readable(): void
    {
        $this->platformAdmin();
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.logo_plate', false));

        $this->platformAdmin();
        $this->patch('/platform/branding/logo-style', ['logo_plate' => 'maybe'])->assertSessionHasErrors('logo_plate');
        $this->patch('/platform/branding/logo-style', ['logo_plate' => true])->assertRedirect()->assertSessionHas('success');
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.logo_plate', true));

        $this->platformAdmin();
        $this->patch('/platform/branding/logo-style', ['logo_plate' => false])->assertRedirect();
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.logo_plate', false));
    }

    public function test_there_is_a_logo_for_light_backgrounds_as_well_as_one_for_dark_ones(): void
    {
        $this->platformAdmin();
        $this->get('/brand/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get('/brand/logo-light')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');          // the built-in pair
        $this->assertNotSame(file_get_contents(public_path('brand/orvyn-logo.svg')), file_get_contents(public_path('brand/orvyn-logo-dark.svg')));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#1d2433"/></svg>';
        $this->post('/platform/branding/logo-light', ['logo' => UploadedFile::fake()->createWithContent('light.svg', $svg)])->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.svg', PlatformSetting::get('login.logo_light_path'));
        $this->assertNull(PlatformSetting::get('login.logo_path'), 'the logo for dark backgrounds is separate');

        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.logo_light_is_default', false)->where('app.logo_is_default', true));
        $this->get('/brand/logo-light')->assertOk();

        $this->platformAdmin();
        $this->delete('/platform/branding/logo-light')->assertRedirect();
        $this->assertNull(PlatformSetting::get('login.logo_light_path'));
    }

    public function test_the_built_in_logo_comes_in_full_colour_or_mono_and_its_address_changes_with_the_file(): void
    {
        $this->platformAdmin();
        $this->get('/brand/logo')->assertOk();
        $colour = $this->get('/brand/logo')->streamedContent();
        $before = app(Company::class)::platformBranding()['logo_url'];
        $this->assertMatchesRegularExpression('/\?v=default-[0-9a-f]{7}$/', $before);                          // a fingerprint of the file, never a fixed word

        $this->patch('/platform/branding/logo-style', ['logo_mono' => 'sometimes'])->assertSessionHasErrors('logo_mono');
        $this->patch('/platform/branding/logo-style', ['logo_mono' => true])->assertRedirect()->assertSessionHas('success', 'The built-in logo is now one colour.');
        PlatformSetting::forgetCache();
        $this->assertNotSame($colour, $this->get('/brand/logo')->streamedContent());
        $this->assertStringContainsString('<mask', $this->get('/brand/logo')->streamedContent());              // one colour: the mark is cut out of a single shape
        $this->assertStringContainsString('<mask', $this->get('/brand/logo-light')->streamedContent());
        $this->assertNotSame($before, app(Company::class)::platformBranding()['logo_url']);
        $this->assertTrue(app(Company::class)::platformBranding()['logo_mono']);

        $this->patch('/platform/branding/logo-style', ['logo_mono' => false])->assertRedirect();
        PlatformSetting::forgetCache();
        $this->assertSame($colour, $this->get('/brand/logo')->streamedContent());
    }

    public function test_the_sign_in_footer_links_and_business_address_are_set_by_the_platform(): void
    {
        $this->platformAdmin();
        $base = ['name' => 'OrvynCRM', 'color' => '#4f46e5', 'theme' => 'light'];
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.footer.privacy_url', '')->where('app.footer.address', ''));       // nothing until it is set

        $this->platformAdmin();
        $this->patch('/platform/branding', $base + ['privacy_url' => 'javascript:alert(1)'])->assertSessionHasErrors('privacy_url');
        $this->patch('/platform/branding', $base + ['contact_url' => 'ftp://example.com'])->assertSessionHasErrors('contact_url');
        $this->patch('/platform/branding', $base + [
            'privacy_url' => 'https://example.com/privacy', 'legal_url' => 'https://example.com/legal', 'terms_url' => 'https://example.com/terms',
            'contact_url' => 'mailto:hello@example.com', 'address' => "1 Main Street\nSpringfield",
        ])->assertSessionHasNoErrors();

        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.footer.terms_url', 'https://example.com/terms')->where('app.footer.contact_url', 'mailto:hello@example.com')->where('app.footer.address', "1 Main Street\nSpringfield"));

        $this->platformAdmin();
        $this->patch('/platform/branding', $base)->assertSessionHasNoErrors();                                   // a save without footer fields leaves them alone
        $this->patch('/platform/branding', $base + ['privacy_url' => '', 'address' => ''])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('app.footer.privacy_url', '')->where('app.footer.address', '')->where('app.footer.terms_url', 'https://example.com/terms'));
    }
}
