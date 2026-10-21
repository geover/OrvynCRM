<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who else gets a copy of an onboarding e-mail (the platform's own people, say): addresses separated by commas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_emails', fn (Blueprint $t) => $t->string('cc', 400)->nullable()->after('tip'));
    }

    public function down(): void
    {
        Schema::table('onboarding_emails', fn (Blueprint $t) => $t->dropColumn('cc'));
    }
};
