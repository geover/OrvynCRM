<?php

namespace Tests\Feature;

use App\Jobs\WorkerBeat;
use App\Services\Heartbeat;
use App\Services\Tenancy\PlatformNotifier;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Knowing whether the scheduler and the queue worker are alive. */
class HeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('heartbeat.scheduler');
        Cache::forget('heartbeat.worker');
        Cache::forget('heartbeat.alerted.scheduler');
        Cache::forget('heartbeat.alerted.worker');
    }

    private function heartbeat(int $mails): Heartbeat
    {
        $notifier = $this->createMock(PlatformNotifier::class);
        $notifier->expects($this->exactly($mails))->method('mail');

        return new Heartbeat($notifier);
    }

    public function test_a_fresh_install_shows_a_problem_but_sends_no_mail(): void
    {
        $this->assertCount(2, $this->heartbeat(0)->problems());
    }

    public function test_both_alive_means_no_problem(): void
    {
        $h = $this->heartbeat(0);
        $h->beat(Heartbeat::SCHEDULER);
        (new WorkerBeat)->handle($h);

        $this->assertSame([], $h->problems());
    }

    public function test_a_scheduler_that_goes_quiet_is_reported_and_mailed_once(): void
    {
        $h = $this->heartbeat(1);
        $h->beat(Heartbeat::SCHEDULER);
        $this->travel(10)->minutes();

        $this->assertCount(2, $h->problems());      // the scheduler (mailed) and the worker, which was never seen (listed, not mailed)
        $h->problems();                              // asked again: no second mail
    }

    public function test_a_worker_that_goes_quiet_while_the_scheduler_runs_is_reported(): void
    {
        $h = $this->heartbeat(1);
        $h->beat(Heartbeat::WORKER);
        $this->travel(10)->minutes();
        $h->beat(Heartbeat::SCHEDULER);

        $problems = $h->problems();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('queue worker', $problems[0]);
    }

    public function test_recovery_clears_the_problem_and_allows_a_new_mail_next_time(): void
    {
        $h = $this->heartbeat(2);
        $h->beat(Heartbeat::SCHEDULER);
        $h->beat(Heartbeat::WORKER);
        $this->travel(10)->minutes();
        $h->beat(Heartbeat::WORKER);
        $h->problems();                              // scheduler stale: mail 1
        $h->beat(Heartbeat::SCHEDULER);
        $this->assertSame([], $h->problems());       // recovered
        $this->travel(10)->minutes();
        $h->beat(Heartbeat::WORKER);
        $h->problems();                              // stale again: mail 2
    }
}
