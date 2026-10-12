<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: the price per additional user a company was quoted when it asked, so the platform sees the same figure. */
    public function up(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->decimal('price_per_user', 10, 2)->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->string('price_period', 10)->nullable();            // month | year
        });
    }

    public function down(): void
    {
        Schema::table('seat_requests', function (Blueprint $table) {
            $table->dropColumn(['price_per_user', 'price_currency', 'price_period']);
        });
    }
};
