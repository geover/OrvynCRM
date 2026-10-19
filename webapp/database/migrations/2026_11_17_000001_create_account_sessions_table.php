<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row for every time somebody signs in, until they sign out or stop being seen: when it started, the last time a request of theirs was seen,
     * how they proved who they are, and from where. The platform's Administrators page reads "last sign-in", "online now" and "time spent" from here.
     */
    public function up(): void
    {
        Schema::create('account_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_user_id')->index();
            $table->dateTime('started_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('ended_at')->nullable();                 // set when they sign out; otherwise the session ended when it was last seen
            $table->string('method', 20)->default('password');       // password | passkey | social
            $table->boolean('two_step')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->index(['account_user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_sessions');
    }
};
