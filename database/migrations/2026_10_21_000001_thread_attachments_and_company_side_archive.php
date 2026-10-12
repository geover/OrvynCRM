<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central: images attached to thread messages, and the company's own archive/delete of a conversation (a company
     * clearing its view never removes the platform's record; the platform has its own archive).
     */
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->timestamp('tenant_archived_at')->nullable();
            $table->timestamp('tenant_deleted_at')->nullable();
        });

        Schema::create('thread_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('thread_messages')->cascadeOnDelete();
            $table->string('path');                                   // on the platform disk
            $table->string('original_name');
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_attachments');
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropColumn(['tenant_archived_at', 'tenant_deleted_at']);
        });
    }
};
