<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: direct messages from a company's administrators to the platform administrators. */
    public function up(): void
    {
        Schema::create('platform_messages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->unsignedBigInteger('sender_account_id')->nullable();
            $table->string('sender_name');
            $table->string('sender_email');
            $table->string('subject', 160);
            $table->text('body');
            $table->timestamp('read_at')->nullable();                 // when a platform administrator first opened it
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_messages');
    }
};
