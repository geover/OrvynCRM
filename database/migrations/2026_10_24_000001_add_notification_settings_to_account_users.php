<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: which e-mails each platform administrator wants (requests, messages) and where to send them (empty = their own address). */
    public function up(): void
    {
        Schema::table('account_users', function (Blueprint $table) {
            $table->boolean('notify_requests')->default(true);
            $table->boolean('notify_messages')->default(true);
            $table->string('notify_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_users', function (Blueprint $table) {
            $table->dropColumn(['notify_requests', 'notify_messages', 'notify_email']);
        });
    }
};
