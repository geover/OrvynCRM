<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: platform administrators can archive updates and requests (hidden, restorable) before deleting them for good. */
    public function up(): void
    {
        foreach (['announcements', 'seat_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['announcements', 'seat_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('archived_at');
            });
        }
    }
};
