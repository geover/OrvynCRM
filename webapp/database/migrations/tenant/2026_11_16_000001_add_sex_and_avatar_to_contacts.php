<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** contacts.sex ('male' | 'female' | null) picks the picture shown when a contact has no photo; avatar_path is an uploaded photo (resized once, on upload). */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('sex', 10)->nullable();
            $t->string('avatar_path')->nullable();
            $t->timestamp('avatar_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropColumn(['sex', 'avatar_path', 'avatar_updated_at']);
        });
    }
};
