<?php

namespace Database\Seeders;

use App\Models\PermissionTemplate;
use Illuminate\Database\Seeder;

/** Runs inside ONE company database when the company is created (tenancy.seeder_parameters). */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        PermissionTemplate::firstOrCreate(['name' => 'Administrator'], ['permissions' => [], 'is_admin' => true]);
        PermissionTemplate::firstOrCreate(['name' => 'Sales'], ['permissions' => [
            'contacts' => ['view', 'create', 'edit'], 'data_objects' => ['view', 'create', 'edit'],
        ]]);
    }
}
