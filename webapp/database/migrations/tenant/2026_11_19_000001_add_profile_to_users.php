<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The basic details of a person in this company: how to reach them, a note for the company's administrators, and a photo (cut to a small square on upload). */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone', 40)->nullable();
            $t->string('job_title', 120)->nullable();
            $t->text('notes')->nullable();
            $t->string('avatar_path')->nullable();
            $t->timestamp('avatar_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['phone', 'job_title', 'notes', 'avatar_path', 'avatar_updated_at']);
        });
    }
};
