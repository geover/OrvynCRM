<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The answers an administrator wrote to a "Contact support" message (each one was also e-mailed to the sender). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_request_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('name');
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_request_replies');
    }
};
