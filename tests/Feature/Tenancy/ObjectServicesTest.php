<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\DispatchDueMessages;
use App\Jobs\NotifyOverdueTasks;
use App\Jobs\SendEmailMessage;
use App\Models\AccountUser;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/** Message sending and the overdue-task digest can be switched off for one data object of a company. */
class ObjectServicesTest extends TenantTestCase
{
    /** @return array{0: Tenant, 1: array{int, int}} a company with two data objects, each with a record */
    private function companyWithTwoObjects(): array
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $records = $acme->run(function () {
            $out = [];
            foreach (['Cases', 'Projects'] as $name) {
                $out[$name] = DataObjectRecord::create(['data_object_id' => DataObject::create(['name' => $name])->id, 'data' => []])->id;
            }

            return array_values($out);
        });

        return [$acme, $records];
    }

    private function queueEmail(?int $recordId): int
    {
        return EmailMessage::create([
            'data_object_record_id' => $recordId, 'subject' => 'Hello', 'html' => '<p>Hi</p>', 'from_email' => 'a@x.test', 'to' => [['email' => 'c@x.test']], 'status' => 'queued',
            'tracker_id' => bin2hex(random_bytes(8)),
        ])->id;
    }

    public function test_only_automations_messages_and_the_digest_can_be_switched_per_data_object(): void
    {
        $this->assertSame(['automations', 'messages', 'task_digest'], CompanyServices::PER_OBJECT);
    }

    public function test_due_messages_of_a_paused_data_object_stay_queued_while_the_others_are_sent(): void
    {
        Queue::fake();
        [$acme, [$casesRecord, $projectsRecord]] = $this->companyWithTwoObjects();
        $services = app(CompanyServices::class);
        $cases = $acme->run(fn () => DataObjectRecord::whereKey($casesRecord)->value('data_object_id'));
        $services->setObject($acme, 'messages', $cases, false);

        [$held, $free, $manual] = $acme->run(fn () => [$this->queueEmail($casesRecord), $this->queueEmail($projectsRecord), $this->queueEmail(null)]);
        $acme->run(fn () => app()->call([new DispatchDueMessages, 'handle']));

        Queue::assertPushed(SendEmailMessage::class, fn ($job) => $job->messageId === $free);
        Queue::assertPushed(SendEmailMessage::class, fn ($job) => $job->messageId === $manual);       // not made by an automation: never held
        Queue::assertNotPushed(SendEmailMessage::class, fn ($job) => $job->messageId === $held);

        $services->setObject($acme, 'messages', $cases, true);                                         // switched back on: it goes out
        $acme->run(fn () => app()->call([new DispatchDueMessages, 'handle']));
        Queue::assertPushed(SendEmailMessage::class, fn ($job) => $job->messageId === $held);
    }

    public function test_a_send_job_already_queued_leaves_a_held_message_alone(): void
    {
        [$acme, [$casesRecord]] = $this->companyWithTwoObjects();
        $services = app(CompanyServices::class);
        $services->setObject($acme, 'messages', $acme->run(fn () => DataObjectRecord::whereKey($casesRecord)->value('data_object_id')), false);
        $id = $acme->run(fn () => $this->queueEmail($casesRecord));

        $acme->run(fn () => app()->call([new SendEmailMessage($id), 'handle']));

        $this->assertSame('queued', $acme->run(fn () => EmailMessage::find($id)->status));
    }

    public function test_the_digest_leaves_out_tasks_made_by_a_paused_data_objects_automations(): void
    {
        [$acme, [$casesRecord]] = $this->companyWithTwoObjects();
        $cases = $acme->run(fn () => DataObjectRecord::whereKey($casesRecord)->value('data_object_id'));
        $userId = $acme->run(fn () => User::value('id'));
        $acme->run(function () use ($userId, $cases) {
            Task::create(['title' => 'By an automation', 'assigned_to' => $userId, 'due_at' => now()->subDay(), 'data_object_id' => $cases]);
            Task::create(['title' => 'By a person', 'assigned_to' => $userId, 'due_at' => now()->subDay()]);
        });
        $digest = fn () => $acme->run(function () use ($userId) {
            DB::table('notifications_feed')->where('user_id', $userId)->delete();
            app()->call([new NotifyOverdueTasks, 'handle']);

            return DB::table('notifications_feed')->where('user_id', $userId)->value('title');
        });

        $this->assertSame('You have 2 overdue tasks', $digest());
        app(CompanyServices::class)->setObject($acme, 'task_digest', $cases, false);
        $this->assertSame('You have 1 overdue task', $digest());
    }

    public function test_the_platform_can_switch_each_of_them_for_one_data_object(): void
    {
        [$acme, [$casesRecord]] = $this->companyWithTwoObjects();
        $cases = $acme->run(fn () => DataObjectRecord::whereKey($casesRecord)->value('data_object_id'));
        AccountUser::create(['name' => 'Platform', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        foreach (['messages', 'task_digest'] as $service) {
            $this->patch("/platform/companies/{$acme->id}/services/objects/{$cases}", ['service' => $service, 'enabled' => false])->assertRedirect();
        }

        $this->assertEqualsCanonicalizing([$cases], app(CompanyServices::class)->pausedObjects($acme, 'messages'));
        $this->assertEqualsCanonicalizing([$cases], app(CompanyServices::class)->pausedObjects($acme, 'task_digest'));
        $this->assertSame([], app(CompanyServices::class)->pausedObjects($acme, 'automations'));
        $this->get("/platform/companies/{$acme->id}")->assertInertia(fn ($p) => $p->has('services.object_catalog', 3));
    }
}
