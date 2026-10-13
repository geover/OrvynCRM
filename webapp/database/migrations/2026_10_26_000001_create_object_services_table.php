<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: a background service switched off for ONE data object of a company (today: its automations). No row means on. */
    public function up(): void
    {
        Schema::create('object_services', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->unsignedBigInteger('data_object_id');
            $table->string('service', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamp('changed_at')->nullable();
            $table->unique(['tenant_id', 'data_object_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_services');
    }
};
