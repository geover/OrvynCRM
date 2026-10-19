<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contacts can unsubscribe themselves: which e-mails carry the unsubscribe footer, and when somebody opted out. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $t) {
            $t->boolean('marketing')->default(false)->after('status');       // automations and blasts: they carry the unsubscribe footer
        });
        Schema::table('contacts', function (Blueprint $t) {
            $t->timestamp('email_opt_out_at')->nullable()->after('email_opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', fn (Blueprint $t) => $t->dropColumn('marketing'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('email_opt_out_at'));
    }
};
