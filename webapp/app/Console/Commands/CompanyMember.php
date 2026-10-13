<?php

namespace App\Console\Commands;

use App\Models\AccountUser;
use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

class CompanyMember extends Command
{
    protected $signature = 'orvyn:company:member
        {company : company slug or id}
        {email : the person}
        {--name= : name, when the person does not have an account yet}
        {--role=user : admin | manager | user}
        {--template= : permission template NAME in that company (e.g. Sales); administrators get Administrator by default}
        {--password= : initial password for a NEW person (otherwise they are e-mailed a link)}
        {--remove : take the person out of the company instead}';

    protected $description = 'Add a person to a company (creating their account if needed), or remove them';

    public function handle(TenantProvisioner $provisioner): int
    {
        $company = Tenant::where('slug', $this->argument('company'))->orWhere('id', $this->argument('company'))->first();
        if (! $company) {
            $this->error('No such company.');

            return self::FAILURE;
        }

        $email = $this->argument('email');
        if ($this->option('remove')) {
            $identity = AccountUser::where('email', mb_strtolower($email))->first();
            $identity ? $provisioner->removeMember($company, $identity) : $this->warn('No such person.');
            $this->info("Removed {$email} from {$company->name}.");

            return self::SUCCESS;
        }

        $existing = AccountUser::where('email', mb_strtolower($email))->exists();
        $identity = $provisioner->identity($this->option('name') ?: $email, $email, $this->option('password'));
        $provisioner->addMember($company, $identity, $this->option('role'), array_filter(['template' => $this->option('template')]));
        if (! $existing && ! $this->option('password')) {
            Password::sendResetLink(['email' => $email]);
        }
        $this->info("{$email} is now a {$this->option('role')} of {$company->name}".($existing ? ' (existing account, password unchanged).' : '.'));

        return self::SUCCESS;
    }
}
