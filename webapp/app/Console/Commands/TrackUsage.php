<?php

namespace App\Console\Commands;

use App\Services\Tenancy\UsageTracker;
use Illuminate\Console\Command;

class TrackUsage extends Command
{
    protected $signature = 'orvyn:track-usage';

    protected $description = 'Measure how much of its plan each company uses (users, texts, texting numbers) and warn at 80 and 100 percent';

    public function handle(UsageTracker $tracker): int
    {
        $this->info("Measured {$tracker->run()} companies.");

        return self::SUCCESS;
    }
}
