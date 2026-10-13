<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central: how many data objects a company may have, how many automations each data object may have (a company-wide
     * default plus per-object overrides that approved requests create), and what requests for more are about.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('max_data_objects')->default(10);
            $table->unsignedInteger('max_automations_per_object')->default(5);
        });

        Schema::create('object_automation_limits', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->unsignedBigInteger('data_object_id');
            $table->unsignedInteger('max_automations');
            $table->timestamps();
            $table->unique(['tenant_id', 'data_object_id']);
        });

        Schema::table('seat_requests', function (Blueprint $table) {
            $table->string('kind', 20)->default('users')->index();             // users | data_objects | automations
            $table->unsignedBigInteger('data_object_id')->nullable();          // automations: which data object
            $table->string('data_object_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropColumn(['kind', 'data_object_id', 'data_object_name']);
        });
        Schema::dropIfExists('object_automation_limits');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['max_data_objects', 'max_automations_per_object']);
        });
    }
};
