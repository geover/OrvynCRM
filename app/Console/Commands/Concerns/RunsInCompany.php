<?php

namespace App\Console\Commands\Concerns;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;

/** Lets a command that works on company data say which company: --company=<slug or id>. */
trait RunsInCompany
{
    /** Opens the chosen company's database; returns false (after telling the user) when it cannot. */
    protected function openCompany(): bool
    {
        $name = $this->option('company');
        if (! $name) {
            if (! config('app.single_database') && Tenant::query()->exists()) {
                $this->error('Several companies may exist: say which one with --company=<slug or id>.');

                return false;
            }

            return true;
        }

        $company = Tenant::where('slug', $name)->orWhere('id', $name)->first();
        if (! $company) {
            $this->error("No such company: {$name}");

            return false;
        }
        tenancy()->initialize($company);
        app()->instance(ResolveTenant::KEEP_OPEN, true);       // survive the requests this command makes through the kernel

        return true;
    }
}
