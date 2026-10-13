<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central: a service that needs details from the company (Keap sync needs its Keap details) can only be switched on by
     * the platform once the company has provided them. `ready` says whether it has; existing Keap rows start not ready
     * and off, because a company has to enter its details in Admin settings anyway.
     */
    public function up(): void
    {
        Schema::table('tenant_services', function (Blueprint $table) {
            $table->boolean('ready')->default(true);
        });
        DB::table('tenant_services')->where('service', 'keap_sync')->update(['ready' => false, 'enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('tenant_services', function (Blueprint $table) {
            $table->dropColumn('ready');
        });
    }
};
