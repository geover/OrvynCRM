<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Extra texts that count for one calendar month only (a boost the platform grants), and which month a granted request was for. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('bonus_sms')->default(0);
            $table->string('bonus_sms_month', 7)->nullable();       // 2026-11: the month the bonus counts for; it ends by itself after it
        });
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->string('granted_month', 7)->nullable();         // set when a texts request was approved for one month only
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', fn (Blueprint $table) => $table->dropColumn('granted_month'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['bonus_sms', 'bonus_sms_month']));
    }
};
