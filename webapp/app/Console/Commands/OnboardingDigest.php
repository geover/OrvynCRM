<?php

namespace App\Console\Commands;

use App\Services\Onboarding\Onboarding;
use Illuminate\Console\Command;

class OnboardingDigest extends Command
{
    protected $signature = 'orvyn:onboarding-digest';

    protected $description = 'Tell the platform administrators which companies need a hand with onboarding (one e-mail a day)';

    public function handle(Onboarding $onboarding): int
    {
        $n = $onboarding->digest();
        $this->info($n ? "Named {$n} companies." : 'Nothing to report.');

        return self::SUCCESS;
    }
}
