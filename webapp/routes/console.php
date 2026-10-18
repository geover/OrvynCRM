<?php

use App\Jobs\TenantTick;
use App\Jobs\WorkerBeat;
use App\Models\Tenant;
use App\Models\TenantMetric;
use App\Services\Heartbeat;
use App\Services\ScheduleSettings;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work  (replaces MacantaCRM/services/_service.php polling loops)
|--------------------------------------------------------------------------
| One cron entry runs everything:   * * * * * php artisan schedule:run
| This file is CENTRAL: it never touches a company's data. Every minute it hands each active company a small TenantTick
| job; the tick (App\Services\Tenancy\CompanyScheduler) runs inside the company on a queue worker and dispatches what is
| due for that company: messages, automations, digests, syncs. Long work happens in queue workers, never in this process.
*/

// Proof of life for the platform area: the scheduler writes a beat itself and hands the queue worker a tiny job that writes its own
Schedule::call(function () {
    app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
    WorkerBeat::dispatch();
})->everyMinute()->name('heartbeat');

Schedule::call(function () {
    $at = now()->startOfMinute()->toIso8601String();
    Tenant::active()->select('id')->chunkById(500, fn ($companies) => $companies->each(fn ($c) => TenantTick::dispatch($c->getTenantKey(), $at)));
})->everyMinute()->name('companies:tick')->withoutOverlapping(2);

// How often these three run is a platform setting (Settings > Background services); the defaults are the ones below
$frequency = app(ScheduleSettings::class);

// Companies nobody has used for a while (the number of days is a platform setting): flag them and tell the people concerned
Schedule::command('orvyn:scan-inactive')->cron($frequency->cron('inactivity'))->name('companies:inactivity')->withoutOverlapping(30);

// How much of its plan each company uses (users, texts, texting numbers): a record per month, and a warning at 80 and 100 percent
Schedule::command('orvyn:track-usage')->cron($frequency->cron('usage'))->name('companies:usage')->withoutOverlapping(30);

// Payments that failed and are still unpaid: remind the company after 3 days and again after 7, and tell the platform
Schedule::command('orvyn:payment-followups')->dailyAt('05:00')->name('companies:payment-followups')->withoutOverlapping(30);

// Free trials running out: remind the administrators 14, 7 and 1 days before, and once when it has ended
Schedule::command('orvyn:trial-reminders')->cron($frequency->cron('trial-reminders'))->name('companies:trial-reminders')->withoutOverlapping(30);

// Central housekeeping (tables that are shared by every company)
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Dashboard metrics are kept for 90 days
Schedule::call(fn () => TenantMetric::where('hour', '<', now()->subDays(90))->delete())->daily()->name('metrics:prune');
