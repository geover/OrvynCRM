<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The other ways to sign in, per person (so they work for every company and the platform): an account at an identity provider, and passkeys. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_user_id')->constrained('account_users')->cascadeOnDelete();
            $t->string('provider', 40);
            $t->string('provider_user_id', 191);          // the provider's own id for the person ("sub"): never the e-mail, which can change
            $t->string('email')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'provider_user_id']);
            $t->unique(['account_user_id', 'provider']);
        });

        Schema::create('passkeys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_user_id')->constrained('account_users')->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('credential_id', 400)->unique();    // base64url
            $t->text('public_key');                        // PEM
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->string('transports')->nullable();           // comma separated
            $t->boolean('backed_up')->default(false);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
        Schema::dropIfExists('social_identities');
    }
};
