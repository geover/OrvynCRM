<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A big emoji above the headline of an onboarding e-mail: it makes each one recognisable at a glance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_emails', fn (Blueprint $t) => $t->string('icon', 16)->nullable()->after('headline'));
    }

    public function down(): void
    {
        Schema::table('onboarding_emails', fn (Blueprint $t) => $t->dropColumn('icon'));
    }
};
