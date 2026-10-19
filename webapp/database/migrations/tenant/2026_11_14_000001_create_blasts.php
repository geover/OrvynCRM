<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per "Email all" / "Text all": what was sent, to how many, by whom, so its progress can be followed as a whole. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blasts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 10);                                  // email | sms
            $t->string('title');                                        // the subject, or the start of the text
            $t->string('source')->nullable();                           // the widget or saved query it went to, or "a search"
            $t->unsignedInteger('matched')->default(0);                 // contacts the query found
            $t->unsignedInteger('queued')->default(0);                  // messages made (the rest had no address or number, or opted out)
            $t->timestamp('finished_queuing_at')->nullable();           // the fan-out has run: no more messages will be added
            $t->timestamps();
            $t->index('created_at');
        });
        Schema::table('email_messages', function (Blueprint $t) {
            $t->unsignedBigInteger('blast_id')->nullable()->index()->after('user_id');
        });
        Schema::table('sms_messages', function (Blueprint $t) {
            $t->unsignedBigInteger('blast_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', fn (Blueprint $t) => $t->dropColumn('blast_id'));
        Schema::table('email_messages', fn (Blueprint $t) => $t->dropColumn('blast_id'));
        Schema::dropIfExists('blasts');
    }
};
