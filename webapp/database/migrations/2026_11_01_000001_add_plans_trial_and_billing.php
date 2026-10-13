<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Licence tiers, the free trial, and the billing details a company gives the platform (central database). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('tier')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->unsignedTinyInteger('trial_notice_stage')->default(0);
        });

        Schema::create('company_billings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('legal_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('tax_id', 60)->nullable();
            $table->string('po_reference', 80)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_billings');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['tier', 'trial_ends_at', 'trial_notice_stage']);
        });
    }
};
