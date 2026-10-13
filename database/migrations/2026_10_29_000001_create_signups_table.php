<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Self-service sign-ups (central): someone asked for a workspace; nothing is created until they have confirmed their e-mail. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signups', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();          // the link in the e-mail carries the token; only its hash is kept
            $table->string('status', 20)->default('pending_email')->index();   // pending_email | verified | pending_approval | approved | completing | completed | rejected
            $table->string('company_name', 120);
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email', 190)->index();
            $table->string('phone', 40)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('tenant_id', 64)->nullable();         // the company that was created
            $table->string('decision_note', 255)->nullable();    // why a request was declined
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signups');
    }
};
