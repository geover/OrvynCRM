<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A "Contact support" message becomes a conversation: the person can write again, and each side knows what it has not read. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $t) {
            $t->boolean('unread_admin')->default(false);
            $t->boolean('unread_user')->default(false);
            $t->timestamp('last_activity_at')->nullable();
        });
        Schema::table('support_request_replies', function (Blueprint $t) {
            $t->string('side', 10)->default('admin');                    // admin | user
        });
    }

    public function down(): void
    {
        Schema::table('support_request_replies', fn (Blueprint $t) => $t->dropColumn('side'));
        Schema::table('support_requests', fn (Blueprint $t) => $t->dropColumn(['unread_admin', 'unread_user', 'last_activity_at']));
    }
};
