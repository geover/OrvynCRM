<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The basic details of a person with an account: how to reach them, where they are, a note for the platform team, and a photo (cut to a small square on upload). */
    public function up(): void
    {
        Schema::table('account_users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('notes')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamp('avatar_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'job_title', 'timezone', 'notes', 'avatar_path', 'avatar_updated_at']);
        });
    }
};
