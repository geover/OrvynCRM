<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central database: everyone who can sign in, which companies they belong to, and the lookup tables that let
 * requests without a session (public forms, webhooks) find their company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->boolean('is_platform_admin')->default(false);
            $t->string('status', 20)->default('active');      // active | suspended (suspended = cannot sign in anywhere)
            $t->timestamps();
        });

        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_user_id')->constrained('account_users')->cascadeOnDelete();
            $t->string('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['account_user_id', 'tenant_id']);
            $t->index('tenant_id');
        });

        // key (form uuid, price-list token, webhook token) -> company, for requests that carry no session
        Schema::create('public_links', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20);                            // form | price_list | webhook
            $t->string('key', 120)->unique();
            $t->string('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->timestamps();
            $t->index('tenant_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['sessions', 'password_reset_tokens', 'public_links', 'memberships', 'account_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
