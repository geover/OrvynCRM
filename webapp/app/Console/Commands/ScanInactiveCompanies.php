<?php

namespace App\Console\Commands;

use App\Services\Tenancy\InactiveCompanies;
use Illuminate\Console\Command;

class ScanInactiveCompanies extends Command
{
    protected $signature = 'orvyn:scan-inactive';

    protected $description = 'Flag companies nobody has used for the number of days the platform chose, and tell the people concerned.';

    public function handle(InactiveCompanies $inactive): int
    {
        if (! $inactive->enabled()) {
            $this->info('The inactivity check is switched off.');

            return self::SUCCESS;
        }
        $result = $inactive->scan();
        $this->info("Flagged {$result['flagged']}, cleared {$result['cleared']} (no activity for {$inactive->days()} days).");

        return self::SUCCESS;
    }
}
