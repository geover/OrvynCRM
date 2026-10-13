<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: a company administrator cleared the outcome of a handled request from the company's pages (the platform keeps the request). */
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->timestamp('tenant_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropColumn('tenant_dismissed_at');
        });
    }
};
