<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Two-step verification (an authenticator app) is per person, so it lives on the one central identity: it covers every company and the platform. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_users', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable()->after('password');                 // encrypted
            $t->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');   // hashes of the codes still unused, as JSON
            $t->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $t->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');   // the 30-second step of the last accepted code: a code works once
        });
    }

    public function down(): void
    {
        Schema::table('account_users', function (Blueprint $t) {
            $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
