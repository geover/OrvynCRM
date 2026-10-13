<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: more of what a company holds (data objects, tasks, notes, ...) and records per data object, for the platform's company overview. */
    public function up(): void
    {
        Schema::table('tenant_stats', function (Blueprint $table) {
            $table->json('counts')->nullable();                 // { key: number } for the overview tiles
            $table->json('object_breakdown')->nullable();       // [{ name, records }] per data object
        });
    }

    public function down(): void
    {
        Schema::table('tenant_stats', function (Blueprint $table) {
            $table->dropColumn(['counts', 'object_breakdown']);
        });
    }
};
