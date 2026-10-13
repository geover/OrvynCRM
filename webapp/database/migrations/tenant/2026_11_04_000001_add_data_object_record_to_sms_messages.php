<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The record whose automation queued the text (null: a person wrote it), like e-mail messages already have. Sending and the
     * message dispatcher look at it to hold the texts of a data object whose message sending is switched off.
     */
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $t) {
            $t->foreignId('data_object_record_id')->nullable()->constrained('data_object_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $t) {
            $t->dropConstrainedForeignId('data_object_record_id');
        });
    }
};
