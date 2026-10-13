<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central: conversations between a company's administrators and the platform's administrators. A new message
     * starts a thread; replies (from either side) stay in it. Replaces the one-way `platform_messages` table, whose
     * rows become one-message threads.
     */
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->string('subject', 160);
            $table->string('started_by_name');
            $table->string('started_by_email');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->boolean('unread_platform')->default(false);              // the company wrote last, no platform administrator has opened it since
            $table->boolean('unread_tenant')->default(false);                // the platform wrote last, no company administrator has opened it since
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('thread_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->string('sender_type', 10);                               // tenant | platform
            $table->unsignedBigInteger('sender_account_id')->nullable();
            $table->string('sender_name');
            $table->string('sender_email');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['thread_id', 'id']);
        });

        if (Schema::hasTable('platform_messages')) {
            foreach (DB::table('platform_messages')->orderBy('id')->get() as $old) {
                $thread = DB::table('message_threads')->insertGetId([
                    'tenant_id' => $old->tenant_id, 'subject' => $old->subject, 'started_by_name' => $old->sender_name, 'started_by_email' => $old->sender_email,
                    'last_message_at' => $old->created_at, 'unread_platform' => $old->read_at === null && $old->archived_at === null, 'unread_tenant' => false,
                    'archived_at' => $old->archived_at, 'created_at' => $old->created_at, 'updated_at' => $old->updated_at,
                ]);
                DB::table('thread_messages')->insert([
                    'thread_id' => $thread, 'sender_type' => 'tenant', 'sender_account_id' => $old->sender_account_id,
                    'sender_name' => $old->sender_name, 'sender_email' => $old->sender_email, 'body' => $old->body, 'created_at' => $old->created_at,
                ]);
            }
            Schema::drop('platform_messages');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_messages');
        Schema::dropIfExists('message_threads');
    }
};
