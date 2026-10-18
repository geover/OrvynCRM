<?php

namespace Tests\Feature;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Services\Tenancy\WebsiteSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/** What the public website says that the platform decides, how the website reads it, and the build hook that rebuilds it. */
class WebsiteSettingsTest extends TenantTestCase
{
    private const HOOK = 'https://93.184.216.34/build_hooks/abc123';

    private function platformAdmin(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    private function form(array $over = []): array
    {
        return $over + ['contact_email' => 'hello@acme.test', 'business_address' => "1 Main St\nManila", 'legal_entity' => 'Acme Software Inc.', 'legal_effective' => '2026-10-13', 'legal_updated' => '2026-11-02', 'demo_form_url' => 'https://app.acme.test/f/abc.js'];
    }

    public function test_the_public_feed_holds_nothing_until_the_platform_sets_something(): void
    {
        $this->getJson('/api/v1/public/website')->assertOk()->assertJsonPath('data.contact_email', null)->assertJsonPath('data.legal_entity', null)->assertJsonPath('data.demo_form_url', null);
    }

    public function test_what_the_platform_saves_is_in_the_public_feed_with_the_dates_as_text(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/settings/website', $this->form())->assertSessionHasNoErrors();
        PlatformSetting::forgetCache();

        $r = $this->getJson('/api/v1/public/website')->assertOk()->assertJsonPath('data.contact_email', 'hello@acme.test')->assertJsonPath('data.legal_effective', 'October 13, 2026')->assertJsonPath('data.legal_updated', 'November 2, 2026');
        $this->assertStringContainsString('public', (string) $r->headers->get('Cache-Control'));
        $this->assertSame("1 Main St\nManila", $r->json('data.business_address'));
    }

    public function test_an_emptied_field_goes_back_to_the_websites_own_default(): void
    {
        $this->platformAdmin();
        $this->patch('/platform/settings/website', $this->form())->assertSessionHasNoErrors();

        $this->patch('/platform/settings/website', $this->form(['contact_email' => '', 'legal_entity' => '']))->assertSessionHasNoErrors();
        PlatformSetting::forgetCache();

        $this->getJson('/api/v1/public/website')->assertJsonPath('data.contact_email', null)->assertJsonPath('data.legal_entity', null)->assertJsonPath('data.demo_form_url', 'https://app.acme.test/f/abc.js');
    }

    public function test_only_a_platform_administrator_can_change_it(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->patch('/platform/settings/website', $this->form())->assertForbidden();
        $this->assertNull(app(WebsiteSettings::class)->publicValues()['contact_email']);
    }

    public function test_what_is_not_an_e_mail_address_or_a_web_address_is_refused(): void
    {
        $this->platformAdmin();

        $this->patch('/platform/settings/website', $this->form(['contact_email' => 'not-an-address']))->assertSessionHasErrors('contact_email');
        $this->patch('/platform/settings/website', $this->form(['demo_form_url' => 'javascript:alert(1)']))->assertSessionHasErrors('demo_form_url');
        $this->patch('/platform/settings/website', $this->form(['legal_effective' => 'someday']))->assertSessionHasErrors('legal_effective');
    }

    public function test_a_build_hook_must_be_a_public_https_address(): void
    {
        $this->platformAdmin();

        foreach (['http://93.184.216.34/hook', 'https://localhost/hook', 'https://127.0.0.1/hook', 'https://10.0.0.5/hook', 'https://build.internal/hook'] as $bad) {
            $this->patch('/platform/settings/website', $this->form(['build_hook' => $bad]))->assertSessionHasErrors('build_hook');
        }
        $this->assertNull(app(WebsiteSettings::class)->hook());
    }

    public function test_the_hook_is_stored_encrypted_and_never_given_back(): void
    {
        Http::fake();
        $this->platformAdmin();

        $this->patch('/platform/settings/website', $this->form(['build_hook' => self::HOOK]))->assertSessionHasNoErrors();
        PlatformSetting::forgetCache();

        $this->assertSame(self::HOOK, app(WebsiteSettings::class)->hook());
        $this->assertStringNotContainsString('abc123', (string) PlatformSetting::get('website.build_hook'));
        $this->get('/platform/settings/website')->assertOk()->assertInertia(fn ($p) => $p->where('website.hook_set', true)->missing('website.build_hook'));
        $this->getJson('/api/v1/public/website')->assertDontSee('abc123');

        $this->patch('/platform/settings/website', $this->form(['clear_hook' => true]))->assertSessionHasNoErrors();
        PlatformSetting::forgetCache();
        $this->assertNull(app(WebsiteSettings::class)->hook());
    }

    public function test_a_change_rebuilds_the_website_and_an_unchanged_save_does_not(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('', 200)]);
        $this->platformAdmin();
        $this->patch('/platform/settings/website', $this->form(['build_hook' => self::HOOK]))->assertSessionHas('success');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::HOOK && $r->method() === 'POST');
        PlatformSetting::forgetCache();

        $this->patch('/platform/settings/website', $this->form())->assertSessionHasNoErrors();      // nothing changed
        Http::assertSentCount(1);

        $this->patch('/platform/settings/website', $this->form(['contact_email' => 'new@acme.test']))->assertSessionHasNoErrors();
        Http::assertSentCount(2);
    }

    public function test_when_the_host_refuses_the_change_is_still_saved_and_a_warning_says_so(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('nope', 500)]);
        $this->platformAdmin();

        $r = $this->patch('/platform/settings/website', $this->form(['build_hook' => self::HOOK]));

        $r->assertSessionHasNoErrors();
        $this->assertStringContainsString('not rebuilt', (string) session('warning'));
        PlatformSetting::forgetCache();
        $this->assertSame('hello@acme.test', app(WebsiteSettings::class)->publicValues()['contact_email']);
        $this->get('/platform/settings/website')->assertInertia(fn ($p) => $p->where('website.last_rebuild.ok', false));
    }

    public function test_the_rebuild_button_needs_a_saved_hook(): void
    {
        Http::fake();
        $this->platformAdmin();

        $this->post('/platform/settings/website/rebuild')->assertSessionHasErrors('build_hook');
        Http::assertNothingSent();
    }
}
