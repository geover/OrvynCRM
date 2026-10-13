<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('legacy_id')->nullable()->unique(); // Keap/Infusionsoft + Macanta contact id
            $t->foreignId('company_id')->nullable()->index();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable()->index();
            $t->string('email_secondary')->nullable();
            $t->string('phone')->nullable()->index();
            $t->string('phone_secondary')->nullable();
            $t->string('company_name')->nullable()->index();
            $t->string('job_title')->nullable();
            $t->string('contact_type', 50)->nullable()->index();
            $t->date('birthday')->nullable();
            $t->json('addresses')->nullable();      // [{type,street1,street2,city,state,zip,country}]
            $t->json('custom_fields')->nullable();  // admin-defined contact fields
            $t->text('notes')->nullable();
            $t->string('timezone', 64)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source', 50)->nullable();
            $t->boolean('email_opt_out')->default(false);
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['last_name', 'first_name']);
            $t->index('created_at');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE contacts ADD FULLTEXT contacts_search (first_name, last_name, email, company_name, phone)');
        }

        Schema::table('users', fn (Blueprint $t) => $t->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete());

        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('legacy_id')->nullable()->unique();
            $t->string('name')->unique();
            $t->string('category')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('contact_tag', function (Blueprint $t) {
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->primary(['contact_id', 'tag_id']);
            $t->index('tag_id');
        });

        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('legacy_id')->nullable()->unique();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title')->nullable();
            $t->text('body');
            $t->json('tags')->nullable();
            $t->timestamps();
            $t->index(['contact_id', 'created_at']);
        });

        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('legacy_id')->nullable()->unique();
            $t->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type', 50)->default('task');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('priority', 10)->default('normal');
            $t->timestamp('due_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['assigned_to', 'completed_at', 'due_at']);
            $t->index(['contact_id', 'completed_at']);
        });

        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $t->nullableMorphs('subject');
            $t->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type', 60)->index();       // contact.updated, email.sent, do.attached ...
            $t->json('data')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['activities', 'tasks', 'notes', 'contact_tag', 'tags'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['contact_id']));
        Schema::dropIfExists('contacts');
    }
};
