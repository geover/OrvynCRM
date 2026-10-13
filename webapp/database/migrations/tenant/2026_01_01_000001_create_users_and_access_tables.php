<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->text('description')->nullable();
            $t->json('permissions');          // {"contacts":["view","edit"],"data_objects":{"<id>":["view"]}}
            $t->boolean('is_admin')->default(false);
            $t->timestamps();
        });

        // A company's profile of a person. Credentials (email, password, name) live once, in the central database
        // (account_users); central_user_id links the two. Role, permission template, caller id etc. are per company.
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('central_user_id')->nullable()->unique();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password')->nullable();      // unused: kept so the framework's user contract still holds
            $t->foreignId('permission_template_id')->nullable()->constrained()->nullOnDelete();
            $t->string('role', 20)->default('user')->index();     // admin | manager | user
            $t->string('status', 20)->default('active')->index(); // active | suspended
            $t->string('timezone', 64)->default('UTC');
            $t->text('signature')->nullable();
            $t->json('preferences')->nullable();
            $t->unsignedBigInteger('contact_id')->nullable()->index(); // FK added once contacts exist
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'users', 'permission_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
