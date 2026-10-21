<?php

namespace App\Console\Commands;

use App\Services\Support\Tickets;
use Illuminate\Console\Command;

class TicketsHousekeeping extends Command
{
    protected $signature = 'orvyn:tickets-housekeeping';

    protected $description = 'Support tickets: close the resolved ones nobody has written on for the number of days the platform chose';

    public function handle(Tickets $tickets): int
    {
        $this->info('Closed '.$tickets->autoClose().' resolved tickets.');

        return self::SUCCESS;
    }
}
