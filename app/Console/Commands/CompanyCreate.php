<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class CompanyCreate extends Command
{
    protected $signature = 'orvyn:company:create
        {name : company name}
        {--slug= : short address (default: from the name)}
        {--owner-name= : administrator name}
        {--owner-email= : administrator email (they sign in with it)}
        {--owner-password= : leave out to e-mail them a link to choose their own}
        {--demo : add sample contacts, a Deals object and tasks}';

    protected $description = 'Create a company: its own database (migrated and seeded) and its first administrator';

    public function handle(TenantProvisioner $provisioner): int
    {
        $owner = null;
        if ($email = $this->option('owner-email')) {
            $owner = ['name' => $this->option('owner-name') ?: $this->argument('name').' Administrator', 'email' => $email, 'password' => $this->option('owner-password')];
        }

        try {
            $company = $provisioner->createCompany($this->argument('name'), $this->option('slug'), $owner, (bool) $this->option('demo'));
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        if ($owner && ! $owner['password']) {
            Password::sendResetLink(['email' => $email]);
            $this->line("A link to choose a password was e-mailed to {$email}.");
        }
        $this->info("Created {$company->name} (id {$company->id}, address {$company->slug}), database {$company->database()->getName()}");

        return self::SUCCESS;
    }
}
