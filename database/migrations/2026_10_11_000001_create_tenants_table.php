<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Companies. One row per company; its data lives in its own database (see config/tenancy.php). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('slug', 63)->unique();
            $table->string('status', 20)->default('active')->index();   // active | suspended
            $table->string('plan', 40)->nullable();
            $table->timestamps();
            $table->json('data')->nullable();                            // stancl: tenancy_db_name etc.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
