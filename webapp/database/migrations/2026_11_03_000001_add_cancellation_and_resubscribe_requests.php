<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cancelling a subscription (who, why, when it ends), the platform's permission to subscribe again, and the requests for that permission. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->string('cancel_reason', 500)->nullable();
            $table->string('canceled_by', 20)->nullable();            // company | platform
            $table->timestamp('canceled_at')->nullable();
            $table->boolean('resubscribe_enabled')->default(false);   // the platform has allowed this company to subscribe again
        });

        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requester_name');
            $table->string('requester_email');
            $table->unsignedTinyInteger('tier')->nullable();          // the plan they would like
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending | approved | declined
            $table->text('response')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_requests');
        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason', 'canceled_by', 'canceled_at', 'resubscribe_enabled']);
        });
    }
};
