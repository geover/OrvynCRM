<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Support\Tenancy\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The platform area has a look of its own (name, colour, logo mark, favicon), separate from the sign-in page. */
class PlatformLookTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
    }

    private function signIn(): void
    {
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    public function test_until_something_is_set_the_platform_area_follows_the_sign_in_page(): void
    {
        PlatformSetting::put('login.name', 'Acme CRM');
        PlatformSetting::put('login.color', '#2563eb');

        $area = Company::platformAreaBranding();

        $this->assertSame(['Acme CRM', '#2563eb'], [$area['name'], $area['color']]);
        $this->assertStringEndsWith('/brand/orvyn-mark.svg', $area['mark_url']);
    }

    public function test_the_platform_look_replaces_the_name_and_colour_of_the_area_only(): void
    {
        PlatformSetting::put('login.name', 'Acme CRM');
        PlatformSetting::put('login.color', '#2563eb');
        $this->signIn();

        $this->patch('/platform/look', ['name' => 'Control Room', 'color' => '#059669'])->assertRedirect()->assertSessionHas('success');

        PlatformSetting::forgetCache();
        $this->assertSame(['Control Room', '#059669'], [Company::platformAreaBranding()['name'], Company::platformAreaBranding()['color']]);
        $this->assertSame(['Acme CRM', '#2563eb'], [Company::platformBranding()['name'], Company::platformBranding()['color']], 'the sign-in page is not touched');
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('app.name', 'Control Room')->where('app.color', '#059669'));
    }

    public function test_changing_the_sign_in_page_no_longer_changes_a_platform_area_that_has_its_own_look(): void
    {
        $this->signIn();
        $this->patch('/platform/look', ['name' => 'Control Room', 'color' => '#059669']);

        $this->patch('/platform/branding', ['name' => 'Other Name', 'color' => '#dc2626', 'theme' => 'dark']);

        PlatformSetting::forgetCache();
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('app.name', 'Control Room')->where('app.color', '#059669'));
        $this->assertSame(['Other Name', '#dc2626'], [Company::platformBranding()['name'], Company::platformBranding()['color']]);
    }

    public function test_emptying_the_fields_goes_back_to_following_the_sign_in_page(): void
    {
        $this->signIn();
        $this->patch('/platform/look', ['name' => 'Control Room', 'color' => '#059669']);
        $this->patch('/platform/look', ['name' => '', 'color' => ''])->assertSessionHasNoErrors();

        PlatformSetting::forgetCache();
        $this->assertSame(Company::platformBranding()['name'], Company::platformAreaBranding()['name']);
        $this->assertSame(Company::platformBranding()['color'], Company::platformAreaBranding()['color']);
    }

    public function test_a_bad_colour_is_refused(): void
    {
        $this->signIn();

        $this->patch('/platform/look', ['name' => 'X', 'color' => 'green'])->assertSessionHasErrors('color');
    }

    public function test_the_logo_mark_and_favicon_can_be_uploaded_and_removed(): void
    {
        Storage::fake('platform');
        $this->signIn();

        $this->post('/platform/look/logo', ['logo' => UploadedFile::fake()->image('mark.png', 64, 64)])->assertRedirect();
        $this->post('/platform/look/favicon', ['favicon' => UploadedFile::fake()->create('icon.png', 4, 'image/png')])->assertRedirect();

        PlatformSetting::forgetCache();
        $area = Company::platformAreaBranding();
        $this->assertStringContainsString('/brand/platform-logo', $area['mark_url']);
        $this->assertStringContainsString('/brand/platform-favicon', $area['favicon_url']);
        $this->get('/brand/platform-logo')->assertOk();

        $this->delete('/platform/look/logo')->assertRedirect();
        $this->delete('/platform/look/favicon')->assertRedirect();
        PlatformSetting::forgetCache();
        $this->assertStringEndsWith('/brand/orvyn-mark.svg', Company::platformAreaBranding()['mark_url']);
        $this->assertStringContainsString('/brand/favicon', Company::platformAreaBranding()['favicon_url']);
    }

    public function test_only_platform_administrators_open_or_change_the_platform_look(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->get('/platform/look')->assertRedirect('/login');
        $this->patch('/platform/look', ['name' => 'X'])->assertRedirect('/login');

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get('/platform/look')->assertForbidden();
    }

    public function test_the_page_and_the_overview_show_it(): void
    {
        $this->signIn();

        $this->get('/platform/look')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Look')->where('look.name', '')->has('look.name_default'));
        $this->get('/platform/settings')->assertOk()->assertInertia(fn ($p) => $p->has('platform_look.name')->has('platform_look.color'));
    }
}
