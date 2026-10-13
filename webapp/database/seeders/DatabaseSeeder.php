<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * CENTRAL seed: the platform administrator and, in local/testing environments, one demo company.
 * (A company's own defaults are seeded when it is created: see TenantDatabaseSeeder.)
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantProvisioner $provisioner): void
    {
        // Credentials come from the environment; never hard-code a production password.
        $provisioner->identity('Platform Administrator', env('PLATFORM_EMAIL', 'platform@orvyn.test'), env('PLATFORM_PASSWORD', env('ADMIN_PASSWORD', 'ChangeMe-123456')), platformAdmin: true);

        if (! app()->environment('local', 'testing') || Tenant::exists()) {
            return;
        }

        $provisioner->createCompany(env('DEMO_COMPANY', 'Demo Company'), env('DEMO_COMPANY_SLUG', 'demo'), [
            'name' => 'Administrator', 'email' => env('ADMIN_EMAIL', 'admin@orvyn.test'), 'password' => env('ADMIN_PASSWORD', 'ChangeMe-123456'),
        ], demo: true);
    }
}
