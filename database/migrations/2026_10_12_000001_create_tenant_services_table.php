<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: per company, which background services are switched on and when each last did something. */
    public function up(): void
    {
        Schema::create('tenant_services', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('service', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_count')->default(0);
            $table->timestamp('changed_at')->nullable();
            $table->unique(['tenant_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_services');
    }
};
