<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Texts and texting numbers a platform administrator gives one company on top of what its tier includes (like Users allowed). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('extra_sms')->default(0);
            $table->unsignedInteger('extra_numbers')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['extra_sms', 'extra_numbers']);
        });
    }
};
