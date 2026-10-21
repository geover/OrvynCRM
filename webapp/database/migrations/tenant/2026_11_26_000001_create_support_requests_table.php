<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A record of every "Contact support" message: who sent it, what it said and which internal addresses it went to. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('name');
            $t->string('email');
            $t->string('subject', 200);
            $t->text('message');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->json('sent_to');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
