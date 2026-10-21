<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\CompanyOnboarding;
use App\Models\Contact;
use App\Models\OnboardingEmail;
use App\Models\OnboardingSend;
use App\Models\Tenant;
use App\Services\Onboarding\DefaultEmails;
use App\Services\Onboarding\Onboarding;
use App\Services\Onboarding\OnboardingMailer;
use App\Services\Onboarding\OnboardingSettings;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Tests\TenantTestCase;

/** The onboarding series: which e-mail goes when, how a company is judged to need a hand, and the page that stops the e-mails. */
class OnboardingTest extends TenantTestCase
{
    private Tenant $alpha;

    private Onboarding $onboarding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
        $this->onboarding = app(Onboarding::class);
    }

    private function keys(CompanyOnboarding $o): array
    {
        return OnboardingSend::where('tenant_id', $o->tenant_id)->where('status', 'sent')->orderBy('id')->pluck('email_key')->all();
    }

    public function test_the_welcome_goes_at_once_then_one_e_mail_per_gap_for_the_first_step_not_taken(): void
    {
        $o = $this->onboarding->start($this->alpha);
        $this->assertSame(9, OnboardingEmail::count());

        $this->onboarding->evaluate($o->fresh());
        $this->assertSame(['welcome'], $this->keys($o));

        $this->onboarding->evaluate($o->fresh());                                      // nothing new yet: the gap has not passed
        $this->assertSame(['welcome'], $this->keys($o));

        $this->travel(4)->days();
        $this->onboarding->evaluate($o->fresh());
        $this->assertSame(['welcome', 'contacts'], $this->keys($o));

        $this->alpha->run(function () {                                                // the company takes that step by itself...
            foreach (['A', 'B', 'C'] as $n) {
                Contact::create(['first_name' => $n, 'last_name' => 'Test']);
            }
        });
        $this->travel(4)->days();
        $this->onboarding->evaluate($o->fresh());                                      // ...so the next e-mail is for the step after it
        $this->assertSame(['welcome', 'contacts', 'data'], $this->keys($o));
        $this->assertArrayHasKey('contacts', $o->fresh()->milestones);
    }

    public function test_a_step_already_taken_is_never_nagged_about_and_the_weekly_limit_holds(): void
    {
        $this->alpha->run(fn () => collect(['A', 'B', 'C'])->each(fn ($n) => Contact::create(['first_name' => $n, 'last_name' => 'Test'])));
        $o = $this->onboarding->start($this->alpha);
        app(OnboardingSettings::class)->save(['gap_days' => 1, 'max_per_week' => 2]);

        $this->onboarding->evaluate($o->fresh());                                      // welcome
        $this->travel(2)->days();
        $this->onboarding->evaluate($o->fresh());                                      // contacts are done: straight to the next step
        $this->assertSame(['welcome', 'data'], $this->keys($o));
        $this->travel(2)->days();
        $this->onboarding->evaluate($o->fresh());                                      // a third in seven days is more than the limit
        $this->assertSame(['welcome', 'data'], $this->keys($o));
    }

    public function test_a_paused_or_unsubscribed_company_gets_nothing_but_is_still_watched(): void
    {
        $o = $this->onboarding->start($this->alpha);
        $this->onboarding->pause($o);
        $this->onboarding->evaluate($o->fresh());
        $this->assertSame([], $this->keys($o));

        $this->get(route('onboarding.stop', $o->unsubscribe_token))->assertOk()->assertSee('Stop the getting-started tips');
        $this->assertSame('paused', $o->fresh()->state);                               // a page view alone changes nothing
        $this->onboarding->resume($o->fresh());
        $this->post(route('onboarding.stop', $o->unsubscribe_token))->assertOk()->assertSee('No more tips');
        $this->assertSame('unsubscribed', $o->fresh()->state);
        $this->onboarding->evaluate($o->fresh());
        $this->assertSame([], $this->keys($o));
        $this->post(route('onboarding.stop', $o->unsubscribe_token).'/resume')->assertOk();
        $this->assertSame('active', $o->fresh()->state);
        $this->get(route('onboarding.stop', 'not-a-real-token'))->assertNotFound();
    }

    public function test_a_company_that_stalls_or_goes_quiet_is_flagged_and_can_be_set_aside(): void
    {
        $o = $this->onboarding->start($this->alpha);
        $this->onboarding->evaluate($o->fresh());
        $this->assertFalse($o->fresh()->needs_help);

        $this->travel(8)->days();                                                      // a week with no new step and nobody signing in
        $this->onboarding->evaluate($o->fresh());
        $o = $o->fresh();
        $this->assertTrue($o->needs_help);
        $this->assertEqualsCanonicalizing(['stalled', 'inactive'], $o->help_reasons);
        $this->assertTrue($o->asksForHelp());
        $this->assertCount(1, $this->onboarding->needingHelp());

        $this->onboarding->handled($o, 7);                                             // the platform has dealt with it
        $this->assertFalse($o->fresh()->asksForHelp());
        $this->assertCount(0, $this->onboarding->needingHelp());
    }

    public function test_when_every_step_is_done_the_all_set_e_mail_goes_once_and_the_series_ends(): void
    {
        $o = $this->onboarding->start($this->alpha);
        $all = ['account' => true, 'contacts' => true, 'data' => true, 'message' => true, 'automation' => true, 'team' => true, 'boards' => true, 'brand' => true];

        $this->assertSame('welcome', $this->onboarding->nextEmail($o, $all)->key);     // the welcome still comes first
        $this->onboarding->sendNext($o->fresh(), $all, 'manual');
        $this->assertSame('all_set', $this->onboarding->nextEmail($o->fresh(), $all)->key);
        $this->onboarding->sendNext($o->fresh(), $all, 'manual');
        $this->assertNull($this->onboarding->nextEmail($o->fresh(), $all));
    }

    public function test_an_e_mail_is_laid_out_with_the_companys_own_details_and_unsafe_text_is_removed(): void
    {
        DefaultEmails::ensure();
        $mail = OnboardingEmail::where('key', 'contacts')->first();
        $mail->update(['body' => '<p>Hi {{owner.first_name}} of {{company.name}} <script>alert(1)</script> {{progress.done}} of {{progress.total}}</p>', 'headline' => 'Hello {{company.name}}']);

        $out = app(OnboardingMailer::class)->render($mail->fresh(), app(OnboardingMailer::class)->sample());
        $this->assertStringContainsString('Hi Maria of Northwind Traders', $out['html']);
        $this->assertStringContainsString('2 of 7', $out['html']);
        $this->assertStringContainsString('Hello Northwind Traders', $out['html']);
        $this->assertStringContainsString('Next up:', $out['html']);                   // the progress tracker and the next step
        $this->assertStringNotContainsString('<script', $out['html']);
        $this->assertStringContainsString('Northwind Traders', $out['text']);
        $this->assertStringContainsString('Hi Maria of Northwind Traders', $out['text']);
    }

    public function test_replies_go_where_the_platform_chose_else_to_the_first_platform_admin_and_copies_and_the_unsubscribe_header_are_sent(): void
    {
        AccountUser::create(['name' => 'Pat Platform', 'email' => 'pat@platform.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        AccountUser::create(['name' => 'Second Admin', 'email' => 'second@platform.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $sent = [];
        Event::listen(MessageSent::class, function ($e) use (&$sent) {
            $sent[] = $e->message;
        });

        $o = $this->onboarding->start($this->alpha);
        OnboardingEmail::where('key', 'welcome')->update(['cc' => 'success@platform.test; bad-address, admin@alpha.test']);   // the owner is never copied to themselves, a bad address is skipped
        $this->onboarding->evaluate($o->fresh());

        $this->assertCount(1, $sent);
        $m = $sent[0];
        $this->assertSame('pat@platform.test', $m->getReplyTo()[0]->getAddress());                    // nobody chose: the first platform administrator
        $this->assertSame(['success@platform.test'], array_map(fn ($a) => $a->getAddress(), $m->getCc()));
        $this->assertSame('admin@alpha.test', $m->getTo()[0]->getAddress());
        $this->assertSame('<'.route('onboarding.stop', $o->unsubscribe_token).'>', $m->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $m->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());

        app(OnboardingSettings::class)->save(['reply_to' => 'help@platform.test']);                  // the platform chose an address
        $this->assertSame(['help@platform.test', ''], app(OnboardingSettings::class)->replyTo());
        $this->travel(4)->days();
        $this->onboarding->evaluate($o->fresh());
        $this->assertSame('help@platform.test', $sent[1]->getReplyTo()[0]->getAddress());
    }

    public function test_each_preview_shows_the_stage_its_e_mail_is_written_for(): void
    {
        DefaultEmails::ensure();
        $mailer = app(OnboardingMailer::class);
        $progress = fn (string $key) => $mailer->sampleFor(OnboardingEmail::where('key', $key)->first())['progress']['done'];

        $this->assertSame(0, $progress('welcome'));                  // nothing done yet
        $this->assertSame(0, $progress('contacts'));                 // about to add the first contacts
        $this->assertSame(2, $progress('message'));                  // contacts and data are done, a message is next
        $this->assertSame(6, $progress('brand'));                    // all but the last step
        $this->assertSame(7, $progress('all_set'));                  // everything
        $this->assertSame('Next up: Build your first automation', 'Next up: '.$mailer->sampleFor(OnboardingEmail::where('key', 'automation')->first())['next']['title']);
    }
}
