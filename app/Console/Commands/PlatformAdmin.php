<?php

namespace App\Console\Commands;

use App\Models\AccountUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

class PlatformAdmin extends Command
{
    protected $signature = 'orvyn:platform-admin {email} {--name=} {--password= : leave out to e-mail a link to choose one} {--revoke : remove platform access}';

    protected $description = 'Create a platform administrator (can create, suspend and open every company), or revoke it';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $person = AccountUser::where('email', $email)->first();

        if ($this->option('revoke')) {
            $person?->update(['is_platform_admin' => false]);
            $this->info($person ? "{$email} is no longer a platform administrator." : 'No such person.');

            return self::SUCCESS;
        }

        $new = ! $person;
        $person ??= new AccountUser(['name' => $this->option('name') ?: 'Platform Administrator', 'email' => $email, 'password' => $this->option('password') ?: str()->random(40)]);
        $person->is_platform_admin = true;
        $person->save();

        if ($new && ! $this->option('password')) {
            Password::sendResetLink(['email' => $email]);
            $this->line('A link to choose a password was e-mailed.');
        }
        $this->info("{$email} is a platform administrator.");

        return self::SUCCESS;
    }
}
