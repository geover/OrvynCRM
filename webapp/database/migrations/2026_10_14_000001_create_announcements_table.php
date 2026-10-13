<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: updates, news and improvements written on the platform and shown on the sign-in page and inside every company. */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('body');
            $table->string('category', 20)->default('update');           // update | news | improvement | fix | maintenance
            $table->timestamp('published_at')->nullable()->index();      // null = draft; in the future = scheduled
            $table->timestamp('expires_at')->nullable();
            $table->boolean('pinned')->default(false);
            $table->boolean('show_on_login')->default(true);
            $table->boolean('show_in_app')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
