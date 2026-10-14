<?php

namespace Tests\Feature;

use App\Services\ScheduleLog;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The schedule listing: every job, its next run, and how the last run went. */
class ScheduleLogTest extends TestCase
{
    public function test_every_job_is_listed_with_a_next_run_and_no_last_run_before_it_has_run(): void
    {
        Cache::flush();
        $rows = app(ScheduleLog::class)->rows();

        $this->assertNotEmpty($rows);
        $this->assertContains('Measure each company against its plan', array_column($rows, 'label'));
        $this->assertNull($rows[0]['last_run']);
        $this->assertNotEmpty($rows[0]['next_run']);
    }

    public function test_a_finished_job_is_remembered_with_how_long_it_took(): void
    {
        Cache::flush();
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->getSummaryForDisplay() === 'companies:usage');

        event(new ScheduledTaskFinished($event, 1.5));

        $row = collect(app(ScheduleLog::class)->rows())->firstWhere('label', 'Measure each company against its plan');
        $this->assertTrue($row['ok']);
        $this->assertSame(1.5, $row['seconds']);
        $this->assertNotNull($row['last_run']);
    }
}
