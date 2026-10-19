<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The company a sign-in is working in (the last one it entered), so a company can see its own people's activity. */
    public function up(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->string('tenant_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
