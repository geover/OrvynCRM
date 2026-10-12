<?php

use App\Jobs\TenantTick;
use App\Models\Tenant;
use App\Models\TenantMetric;
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

Schedule::call(function () {
    $at = now()->startOfMinute()->toIso8601String();
    Tenant::active()->select('id')->chunkById(500, fn ($companies) => $companies->each(fn ($c) => TenantTick::dispatch($c->getTenantKey(), $at)));
})->everyMinute()->name('companies:tick')->withoutOverlapping(2);

// Central housekeeping (tables that are shared by every company)
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Dashboard metrics are kept for 90 days
Schedule::call(fn () => TenantMetric::where('hour', '<', now()->subDays(90))->delete())->daily()->name('metrics:prune');
