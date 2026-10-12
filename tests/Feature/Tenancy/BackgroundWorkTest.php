<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\DispatchDueMessages;
use App\Jobs\EvaluateAutomation;
use App\Jobs\NotifyOverdueTasks;
use App\Jobs\ProcessDataExport;
use App\Jobs\ProcessDataImport;
use App\Jobs\QueueBatchMessages;
use App\Jobs\ReindexDataObject;
use App\Jobs\SyncKeapContacts;
use App\Jobs\TenantTick;
use App\Models\Automation;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Services\Tenancy\CompanyScheduler;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordCompanyJob;
use Tests\TenantTestCase;

class BackgroundWorkTest extends TenantTestCase
{
    private function automation(string $schedule, string $name = 'A'): int
    {
        return Automation::create(['name' => $name, 'schedule' => $schedule, 'status' => 'active', 'conditions' => [], 'actions' => [['type' => 'add_tag', 'tag' => 'x']]])->id;
    }

    public function test_the_central_scheduler_hands_a_tick_to_every_active_company_only(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $c = $this->company('Gamma', 'c@gamma.test');
        $c->update(['status' => 'suspended']);
        Queue::fake();

        $this->artisan('schedule:run')->assertExitCode(0);

        Queue::assertPushed(TenantTick::class, 2);
        Queue::assertPushed(TenantTick::class, fn (TenantTick $t) => $t->tenantId === $a->id && $t->queue === 'ticks');
        Queue::assertPushed(TenantTick::class, fn (TenantTick $t) => $t->tenantId === $b->id);
        Queue::assertNotPushed(TenantTick::class, fn (TenantTick $t) => $t->tenantId === $c->id);
    }

    public function test_a_tick_dispatches_only_what_is_due_for_that_company(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $scheduler = app(CompanyScheduler::class);
        $noon = Carbon::parse('2026-03-04 12:00:00', 'UTC');
        Queue::fake();

        // nothing queued, one every-minute automation: only the evaluation
        $a->run(fn () => $this->automation('every_minute'));
        $this->assertSame(['automation:every_minute:1'], $a->run(fn () => $scheduler->run($noon->copy()->setMinute(7))));

        // an email waiting to go out makes the messages dispatcher run, in that company
        $b->run(fn () => EmailMessage::create(['subject' => 'Hi', 'from_email' => 'b@beta.test', 'to' => ['x@y.test'], 'html' => '<p>hi</p>', 'scheduled_at' => now()->subMinute()]));
        $this->assertSame(['messages'], $b->run(fn () => $scheduler->run($noon->copy()->setMinute(7))));

        // a quiet company on a quiet minute costs nothing
        $quiet = $this->company('Quiet', 'q@quiet.test');
        $this->assertSame([], $quiet->run(fn () => $scheduler->run($noon->copy()->setMinute(7))));

        // on the hour: hourly automations + the overdue digest
        $a->run(fn () => $this->automation('hourly', 'H'));
        $done = $a->run(fn () => $scheduler->run($noon));
        $this->assertContains('overdue-digest', $done);
        $this->assertContains('automation:hourly:2', $done);
        $this->assertNotContains('automation:daily:3', $done);
    }

    public function test_daily_automations_follow_the_companys_own_timezone(): void
    {
        $manila = $this->company('Manila Co', 'm@manila.test');
        $utc = $this->company('Utc Co', 'u@utc.test');
        $manila->run(function () {
            Setting::put('app.timezone', 'Asia/Manila');
            $this->automation('daily');
        });
        $utc->run(fn () => $this->automation('daily'));
        $scheduler = app(CompanyScheduler::class);
        Queue::fake();

        $midnightInManila = Carbon::parse('2026-03-04 16:00:00', 'UTC');                    // 00:00 on 5 March in Manila (UTC+8)
        $this->assertContains('automation:daily:1', $manila->run(fn () => $scheduler->run($midnightInManila)));
        $this->assertNotContains('automation:daily:1', $utc->run(fn () => $scheduler->run($midnightInManila)));
        $this->assertContains('automation:daily:1', $utc->run(fn () => $scheduler->run(Carbon::parse('2026-03-05 00:00:00', 'UTC'))));
    }

    public function test_a_stale_or_suspended_tick_does_nothing(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $a->run(fn () => $this->automation('every_minute'));
        Queue::fake();

        (new TenantTick($a->id, now()->subMinutes(5)->toIso8601String()))->handle(app(CompanyScheduler::class));
        Queue::assertNotPushed(EvaluateAutomation::class);

        $a->update(['status' => 'suspended']);
        (new TenantTick($a->id, now()->toIso8601String()))->handle(app(CompanyScheduler::class));
        Queue::assertNotPushed(EvaluateAutomation::class);

        $a->update(['status' => 'active']);
        (new TenantTick($a->id, now()->startOfMinute()->toIso8601String()))->handle(app(CompanyScheduler::class));
        Queue::assertPushed(EvaluateAutomation::class);
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_queued_jobs_run_against_the_company_that_dispatched_them(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        config(['queue.default' => 'database']);
        app('queue')->setDefaultDriver('database');

        // dispatch NOW, inside the company: a PendingDispatch returned out of run() would only be sent when it is
        // destroyed, after run() has already handed the connection back
        $dispatcher = app(Dispatcher::class);
        $a->run(function () use ($dispatcher) {
            $dispatcher->dispatch(new RecordCompanyJob);
        });
        $b->run(function () use ($dispatcher) {
            $dispatcher->dispatch(new RecordCompanyJob);
        });
        $this->assertSame(2, DB::table('jobs')->count(), 'both jobs wait in the ONE central queue');
        $this->assertStringContainsString($a->id, DB::table('jobs')->orderBy('id')->value('payload'), 'the payload carries the company');

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => 'default', '--tries' => 1]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame($a->id, $a->run(fn () => Setting::get('job.ran_in')));
        $this->assertSame($b->id, $b->run(fn () => Setting::get('job.ran_in')));
        $this->assertFalse(tenancy()->initialized, 'the worker leaves no company open for the next job');
    }

    public function test_long_jobs_use_a_connection_whose_visibility_timeout_outlasts_them(): void
    {
        // the default connection must outlast the longest SHORT job, the long one the longest long job
        $longest = fn (array $classes) => max(array_map(fn ($c) => (new \ReflectionClass($c))->getDefaultProperties()['timeout'] ?? 60, $classes));
        $longJobs = [ProcessDataImport::class, ProcessDataExport::class, QueueBatchMessages::class, ReindexDataObject::class, SyncKeapContacts::class];

        foreach (['database', 'redis'] as $driver) {
            $this->assertGreaterThan(300, config("queue.connections.{$driver}.retry_after"), "{$driver}: must outlast EvaluateAutomation (300 s)");
            $this->assertGreaterThan($longest($longJobs), config("queue.connections.{$driver}-long.retry_after"), "{$driver}-long must outlast the longest long job");
        }
        $this->assertSame('sync', config('queue.long'), 'sync stays sync (the test environment)');

        config(['queue.long' => 'database-long']);
        $jobs = [new ProcessDataImport(1), new ProcessDataExport(1), new QueueBatchMessages(1, 'email', [], 1), new ReindexDataObject(1), new SyncKeapContacts];
        foreach ($jobs as $job) {
            $this->assertSame('database-long', $job->connection, get_class($job).' must run on the long connection');
        }
        $this->assertNotSame('database-long', (new NotifyOverdueTasks)->connection);
        $this->assertNotSame('database-long', (new DispatchDueMessages)->connection);
    }

    public function test_one_evaluation_of_an_automation_at_a_time(): void
    {
        $middleware = (new EvaluateAutomation(7))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertGreaterThan((new EvaluateAutomation(7))->timeout, $middleware[0]->expiresAfter, 'a crashed worker must not hold the lock forever, but not release it while running either');
        $this->assertNull($middleware[0]->releaseAfter, 'a second pass is dropped, not re-queued behind the first');
    }

    public function test_queue_status_reports_depth_and_exits_non_zero_over_the_limit(): void
    {
        config(['queue.default' => 'database']);
        $now = now()->getTimestamp();
        foreach (range(1, 3) as $i) {
            DB::table('jobs')->insert(['queue' => 'messages', 'payload' => json_encode(['tenant_id' => 'abc']), 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - 400, 'created_at' => $now - 400]);
        }

        $this->artisan('orvyn:queue-status', ['--alert-over' => 2000, '--oldest-over' => 9999])->assertExitCode(0)->expectsOutputToContain('OK');
        $this->artisan('orvyn:queue-status', ['--alert-over' => 2])->assertExitCode(1)->expectsOutputToContain('messages: 3 pending');
        $this->artisan('orvyn:queue-status', ['--oldest-over' => 300])->assertExitCode(1)->expectsOutputToContain('oldest job has waited');
        $this->artisan('orvyn:queue-status', ['--quiet-ok' => true, '--alert-over' => 2000, '--oldest-over' => 9999])->assertExitCode(0);
    }
}
