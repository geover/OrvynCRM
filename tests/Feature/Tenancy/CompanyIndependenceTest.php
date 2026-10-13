<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\DispatchDueMessages;
use App\Jobs\EvaluateAutomation;
use App\Jobs\SendEmailMessage;
use App\Models\Automation;
use App\Models\DataObject;
use App\Models\EmailMessage;
use App\Services\Tenancy\CompanyScheduler;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/** Companies share the platform's workers and nothing else: what is switched, scheduled or sent for one never touches another. */
class CompanyIndependenceTest extends TenantTestCase
{
    private function queueEmail(string $subject = 'Hello'): int
    {
        return EmailMessage::create([
            'subject' => $subject, 'html' => '<p>Hi</p>', 'from_email' => 'a@x.test', 'to' => [['email' => 'c@x.test']], 'status' => 'queued', 'tracker_id' => bin2hex(random_bytes(8)),
        ])->id;
    }

    public function test_switching_a_service_off_for_one_company_leaves_every_other_company_alone(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $services = app(CompanyServices::class);

        foreach (['messages', 'automations', 'task_digest', 'housekeeping'] as $service) {
            $services->set($acme, $service, false);
        }

        foreach (['messages', 'automations', 'task_digest', 'housekeeping'] as $service) {
            $this->assertFalse($services->states($acme)[$service]['enabled']);
            $this->assertTrue($services->states($beta)[$service]['enabled'], "{$service} of the other company");
        }
    }

    public function test_a_data_object_switch_belongs_to_its_company_even_when_two_companies_use_the_same_object_number(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $services = app(CompanyServices::class);
        $acmeObject = $acme->run(fn () => DataObject::create(['name' => 'Cases'])->id);
        $betaObject = $beta->run(fn () => DataObject::create(['name' => 'Cases'])->id);
        $this->assertSame($acmeObject, $betaObject, 'each company counts its own data objects from 1');

        $services->setObject($acme, 'automations', $acmeObject, false);

        $this->assertFalse($services->objectEnabled('automations', $acmeObject, $acme));
        $this->assertTrue($services->objectEnabled('automations', $betaObject, $beta));
        $this->assertSame([], $services->pausedObjects($beta, 'automations'));
    }

    public function test_the_platform_can_switch_a_service_on_without_a_warning(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $services = app(CompanyServices::class);

        $services->set($acme, 'messages', false);
        $services->set($acme, 'messages', true);          // the message of a refusal used to be built for every service and warned for most

        $this->assertTrue($services->states($acme)['messages']['enabled']);
    }

    public function test_each_companys_scheduler_minute_follows_only_its_own_switches(): void
    {
        Queue::fake();
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $services = app(CompanyServices::class);
        foreach ([$acme, $beta] as $company) {
            $company->run(function () {
                $this->queueEmail();
                Automation::create(['name' => 'Every minute', 'data_object_id' => DataObject::create(['name' => 'Cases'])->id, 'status' => 'active', 'schedule' => 'every_minute', 'conditions' => ['match' => 'all', 'rules' => []], 'actions' => []]);
            });
        }
        $services->set($acme, 'messages', false);
        $services->set($acme, 'automations', false);
        $at = now()->startOfMinute();

        $acmeDid = $acme->run(fn () => app(CompanyScheduler::class)->run($at));
        $betaDid = $beta->run(fn () => app(CompanyScheduler::class)->run($at));

        $this->assertNotContains('messages', $acmeDid);
        $this->assertSame([], array_filter($acmeDid, fn ($d) => str_starts_with($d, 'automation:')));
        $this->assertContains('messages', $betaDid);
        $this->assertNotEmpty(array_filter($betaDid, fn ($d) => str_starts_with($d, 'automation:')));
        Queue::assertPushed(EvaluateAutomation::class, 1);
    }

    public function test_pressing_send_in_a_paused_company_holds_the_message_until_it_is_switched_on(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $services = app(CompanyServices::class);
        [$acmeMail, $betaMail] = [$acme->run(fn () => $this->queueEmail()), $beta->run(fn () => $this->queueEmail())];
        $services->set($acme, 'messages', false);

        $acme->run(fn () => app()->call([new SendEmailMessage($acmeMail), 'handle']));
        $beta->run(fn () => app()->call([new SendEmailMessage($betaMail), 'handle']));

        $this->assertSame('queued', $acme->run(fn () => EmailMessage::find($acmeMail)->status), 'held while paused');
        $this->assertSame('sent', $beta->run(fn () => EmailMessage::find($betaMail)->status), 'the other company is not held');

        $services->set($acme, 'messages', true);
        $acme->run(fn () => app()->call([new DispatchDueMessages, 'handle']));
        $this->assertSame('sent', $acme->run(fn () => EmailMessage::find($acmeMail)->status), 'goes out once it is switched back on');
    }

    public function test_one_companys_backlog_hands_the_shared_queue_only_one_minutes_worth(): void
    {
        Queue::fake();
        config(['queue.messages_per_tick' => 3]);
        $acme = $this->company('Acme', 'owner@acme.test');
        $ids = $acme->run(fn () => collect(range(1, 10))->map(fn ($i) => $this->queueEmail("Backlog {$i}"))->all());

        $acme->run(fn () => app()->call([new DispatchDueMessages, 'handle']));

        Queue::assertPushed(SendEmailMessage::class, 3);
        Queue::assertPushed(SendEmailMessage::class, fn ($job) => in_array($job->messageId, array_slice($ids, 0, 3), true));          // oldest first
    }
}
