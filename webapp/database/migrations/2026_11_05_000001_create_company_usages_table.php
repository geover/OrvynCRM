<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per company per month: how much of its tier it used (active users, texts, texting numbers), and which warnings were sent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_usages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('period', 7);                              // 2026-11
            $table->unsignedTinyInteger('tier');
            $table->unsignedInteger('users')->default(0);             // the most active users seen this month
            $table->unsignedInteger('users_limit')->nullable();       // null: unlimited
            $table->unsignedInteger('sms')->default(0);               // texts sent this month
            $table->unsignedInteger('sms_limit');
            $table->unsignedInteger('numbers')->default(0);
            $table->unsignedInteger('numbers_limit');
            $table->unsignedTinyInteger('users_alert')->default(0);   // 0, 80 or 100: the last warning sent
            $table->unsignedTinyInteger('sms_alert')->default(0);
            $table->unsignedTinyInteger('numbers_alert')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_usages');
    }
};
