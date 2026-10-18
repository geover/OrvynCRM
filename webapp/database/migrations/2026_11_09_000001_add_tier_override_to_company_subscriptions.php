<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A platform administrator can put a paying company on another tier without touching its Stripe subscription (a gift, a goodwill upgrade). Stripe events must then not put it back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->boolean('tier_override')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('company_subscriptions', fn (Blueprint $table) => $table->dropColumn('tier_override'));
    }
};
