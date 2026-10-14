<?php

namespace App\Jobs;

use App\Services\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Handed out by the scheduler every minute: when a worker runs it, the worker is alive. */
class WorkerBeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('ticks');
    }

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->beat(Heartbeat::WORKER);
    }
}
