<?php

namespace App\Console\Commands;

use App\Services\Tenancy\AccountStanding;
use Illuminate\Console\Command;

class PaymentFollowUps extends Command
{
    protected $signature = 'orvyn:payment-followups';

    protected $description = 'Remind the companies whose payment failed (after 3 and after 7 days) and tell the platform when one is still unpaid after 7.';

    public function handle(AccountStanding $standing): int
    {
        $this->info($standing->followUps().' reminders sent.');

        return self::SUCCESS;
    }
}
