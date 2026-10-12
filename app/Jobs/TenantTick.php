<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\CompanyScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * One company's scheduler minute. The central scheduler (routes/console.php) dispatches one per active company; the
 * work itself runs here, inside the company, on a worker, so a slow or broken company never delays the scheduler or
 * another company.
 */
class TenantTick implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** A tick that waited longer than this is stale: the next minute's tick covers it. */
    public int $expiresAfterSeconds = 90;

    public function __construct(public string $tenantId, public string $at)
    {
        $this->onQueue('ticks');
    }

    public function handle(CompanyScheduler $scheduler): void
    {
        $at = Carbon::parse($this->at);
        if ($at->diffInSeconds(now()) > $this->expiresAfterSeconds) {
            return;
        }

        $tenant = Tenant::find($this->tenantId);
        if (! $tenant?->isActive()) {
            return;
        }

        $tenant->run(fn () => $scheduler->run($at));
    }
}
