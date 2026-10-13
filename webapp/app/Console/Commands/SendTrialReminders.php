<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TrialReminders;
use Illuminate\Console\Command;

class SendTrialReminders extends Command
{
    protected $signature = 'orvyn:trial-reminders';

    protected $description = 'Tell company administrators that their free trial is ending (14, 7 and 1 days before, and when it has ended)';

    public function handle(TrialReminders $reminders): int
    {
        $this->info("Told {$reminders->run()} companies.");

        return self::SUCCESS;
    }
}
