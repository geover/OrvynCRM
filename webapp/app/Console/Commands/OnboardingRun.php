<?php

namespace App\Console\Commands;

use App\Services\Onboarding\Onboarding;
use Illuminate\Console\Command;

class OnboardingRun extends Command
{
    protected $signature = 'orvyn:onboarding-run';

    protected $description = 'Onboarding series: read what each new company has done, send the e-mail that is due and flag the companies that need a hand';

    public function handle(Onboarding $onboarding): int
    {
        $r = $onboarding->run();
        $this->info("Checked {$r['checked']} companies, sent {$r['sent']} e-mails, {$r['flagged']} need a hand.");

        return self::SUCCESS;
    }
}
