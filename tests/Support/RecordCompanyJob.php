<?php

namespace Tests\Support;

use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Test job: writes down which company's database it ran against. */
class RecordCompanyJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Setting::put('job.ran_in', tenant('id'));
    }
}
