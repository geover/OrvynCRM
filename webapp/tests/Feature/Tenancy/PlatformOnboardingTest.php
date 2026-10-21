<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\CompanyOnboarding;
use App\Models\OnboardingEmail;
use App\Models\OnboardingSend;
use App\Models\Tenant;
use App\Services\Onboarding\Onboarding;
use Tests\TenantTestCase;

/** The platform's view of onboarding: who is in the series and how far, what can be done to a company, and the e-mails and settings. */
class PlatformOnboardingTest extends TenantTestCase
{
    private Tenant $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
        $this->company('Beta', 'owner@beta.test');
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    public function test_the_page_lists_the_companies_in_the_series_and_offers_the_others(): void
    {
        app(Onboarding::class)->start($this->alpha);

        $this->get('/platform/onboarding')->assertOk()->assertInertia(function ($p) {
            $p->component('Platform/Onboarding');
            $props = $p->toArray()['props'];
            $this->assertSame(['Alpha'], array_column($props['rows'], 'name'));
            $this->assertSame(['Beta'], array_column($props['unstarted'], 'name'));            // not in the series: can be added by hand
            $this->assertSame(9, count($props['emails']));
            $this->assertSame(7, count($props['steps']));
            $this->assertSame(1, $props['summary']['total']);
        });

        $beta = Tenant::where('name', 'Beta')->first();
        $this->postJson("/platform/onboarding/companies/{$beta->id}/start")->assertOk();
        $this->assertTrue(CompanyOnboarding::where('tenant_id', $beta->id)->exists());
    }

    public function test_the_platform_can_send_the_next_e_mail_pause_and_set_a_flag_aside(): void
    {
        $o = app(Onboarding::class)->start($this->alpha);

        $this->postJson("/platform/onboarding/companies/{$this->alpha->id}/send-next")->assertOk()->assertJsonPath('ok', true);
        $send = OnboardingSend::where('tenant_id', $o->tenant_id)->first();
        $this->assertSame(['welcome', 'manual', 'sent', 'admin@alpha.test'], [$send->email_key, $send->trigger, $send->status, $send->to]);
        $this->postJson("/platform/onboarding/companies/{$this->alpha->id}/send-next")->assertOk();                      // by hand, whatever the gap says
        $this->assertSame('contacts', OnboardingSend::where('tenant_id', $o->tenant_id)->orderByDesc('id')->first()->email_key);

        $this->postJson("/platform/onboarding/companies/{$this->alpha->id}/pause")->assertOk();
        $this->assertSame('paused', $o->fresh()->state);
        $this->postJson("/platform/onboarding/companies/{$this->alpha->id}/resume")->assertOk();
        $this->assertSame('active', $o->fresh()->state);

        $o->forceFill(['needs_help' => true, 'help_reasons' => ['stalled']])->save();
        $this->postJson("/platform/onboarding/companies/{$this->alpha->id}/handled", ['days' => 14])->assertOk();
        $this->assertFalse($o->fresh()->asksForHelp());
        $this->postJson("/platform/onboarding/companies/{$this->alpha->id}/handled", ['days' => 400])->assertUnprocessable();

        $this->getJson("/platform/onboarding/companies/{$this->alpha->id}")->assertOk()
            ->assertJsonPath('data.owner.email', 'admin@alpha.test')->assertJsonCount(8, 'data.steps')->assertJsonPath('data.sends.0.key', 'contacts');
    }

    public function test_the_e_mails_are_edited_previewed_and_tested_and_the_settings_are_kept_within_range(): void
    {
        app(Onboarding::class)->start($this->alpha);
        $e = OnboardingEmail::where('key', 'contacts')->first();

        $this->patchJson("/platform/onboarding/emails/{$e->id}", ['title' => 'First contacts', 'subject' => 'New subject for {{company.name}}', 'headline' => 'A new headline', 'body' => '<p>Hello <script>x()</script>{{owner.first_name}}</p>',
            'button_label' => 'Go', 'button_path' => '/contacts', 'tip' => 'A tip', 'enabled' => true])->assertOk()->assertJsonPath('data.subject', 'New subject for {{company.name}}');
        $this->assertStringNotContainsString('<script', $e->fresh()->body);
        $this->patchJson("/platform/onboarding/emails/{$e->id}", ['title' => 'x', 'subject' => 'x', 'headline' => 'x', 'body' => 'x', 'button_path' => 'https://evil.test'])->assertUnprocessable();   // a page of the app only

        $preview = $this->postJson("/platform/onboarding/emails/{$e->id}/preview", ['title' => 'x', 'subject' => 'Hi {{company.name}}', 'headline' => 'Draft headline', 'body' => '<p>Draft {{owner.first_name}}</p>'])->assertOk();
        $preview->assertJsonPath('subject', 'Hi Northwind Traders');                      // the text as typed, not as saved
        $this->assertStringContainsString('Draft headline', $preview->json('html'));
        $this->assertStringContainsString('Draft Maria', $preview->json('html'));
        $this->postJson("/platform/onboarding/emails/{$e->id}/test")->assertOk()->assertJsonPath('message', 'A test was sent to platform@x.test.');

        $this->patchJson('/platform/onboarding/settings', ['gap_days' => 5, 'max_per_week' => 3, 'alert_digest' => false])->assertOk()->assertJsonPath('data.gap_days', 5)->assertJsonPath('data.alert_digest', false);
        $this->patchJson('/platform/onboarding/settings', ['reply_to' => 'not-an-address'])->assertUnprocessable();
        $this->patchJson('/platform/onboarding/settings', ['reply_to' => 'help@platform.test'])->assertOk()->assertJsonPath('data.reply_to', 'help@platform.test')->assertJsonPath('replying_to.address', 'help@platform.test');
        $this->patchJson("/platform/onboarding/emails/{$e->id}", ['title' => 'x', 'subject' => 'x', 'headline' => 'x', 'body' => 'x', 'cc' => 'one@platform.test, two@platform.test'])->assertOk()->assertJsonPath('data.cc', 'one@platform.test, two@platform.test');
        $this->patchJson("/platform/onboarding/emails/{$e->id}", ['title' => 'x', 'subject' => 'x', 'headline' => 'x', 'body' => 'x', 'cc' => 'one@platform.test, nope'])->assertUnprocessable()->assertJsonValidationErrors('cc');
        $this->patchJson('/platform/onboarding/settings', ['gap_days' => 0])->assertUnprocessable();
        $this->patchJson('/platform/onboarding/settings', ['max_per_week' => 9])->assertUnprocessable();
    }
}
