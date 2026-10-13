<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowing when a company was last used (so abandoned ones can be found), and the state the platform put it in when it was
 * flagged, closed or suspended for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index();      // someone used the company (opened a page or changed something), at most every 10 minutes
            $table->timestamp('inactive_since')->nullable();               // flagged as inactive by the daily check; cleared when the company is used again
            $table->timestamp('closed_at')->nullable();
            $table->string('status_note', 255)->nullable();                // why the platform suspended or closed it
        });
        DB::table('tenants')->update(['last_active_at' => now()]);          // the clock of every existing company starts now, so none is flagged on day one

        Schema::table('account_users', function (Blueprint $table) {
            $table->boolean('notify_inactive_companies')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('account_users', fn (Blueprint $table) => $table->dropColumn('notify_inactive_companies'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['last_active_at', 'inactive_since', 'closed_at', 'status_note']));
    }
};
