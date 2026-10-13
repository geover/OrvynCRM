<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: platform administrators who want an e-mail when a company's automation run fails (opt-in). */
    public function up(): void
    {
        Schema::table('account_users', function (Blueprint $table) {
            $table->boolean('notify_automation_failures')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('account_users', function (Blueprint $table) {
            $table->dropColumn('notify_automation_failures');
        });
    }
};
