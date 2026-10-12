<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('middle_name')->nullable()->after('first_name');
            $t->string('title', 50)->nullable()->after('last_name');
            $t->string('website')->nullable();
            $t->json('emails')->nullable();   // extra e-mail addresses [{email,type}]
            $t->json('phones')->nullable();   // extra phones [{number,ext,type}]
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('caller_id', 40)->nullable();   // outbound caller id for telephony
        });

        Schema::table('widgets', function (Blueprint $t) {
            $t->unsignedSmallInteger('position')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = shared
        });

        Schema::create('contact_relationship_types', function (Blueprint $t) {
            $t->id();
            $t->string('label')->unique();
            $t->text('description')->nullable();
            $t->string('kind', 10)->default('direct');   // direct (contact<->contact) | indirect (via shared data object)
            $t->timestamps();
        });

        Schema::create('contact_relationships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('related_contact_id')->constrained('contacts')->cascadeOnDelete();
            $t->foreignId('type_id')->constrained('contact_relationship_types')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['contact_id', 'related_contact_id', 'type_id'], 'contact_rel_unique');
            $t->index('related_contact_id');
        });

        Schema::create('contact_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->string('disk', 30)->nullable();
            $t->string('path')->nullable();
            $t->string('url', 2000)->nullable();   // link attachments
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamps();
        });

        Schema::create('workflow_boards', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->foreignId('data_object_id')->constrained()->cascadeOnDelete();
            $t->string('key_field', 100);                    // select field whose choices become columns
            $t->foreignId('relationship_id')->nullable()->constrained('data_object_relationships')->nullOnDelete();
            $t->json('display_fields')->nullable();          // field keys shown on cards
            $t->json('colors')->nullable();                  // {choice: '#hex'}
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });

        Schema::create('schedules', function (Blueprint $t) {  // admin "Scheduler" definitions over data objects
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->foreignId('data_object_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_relationship_id')->nullable()->constrained('data_object_relationships')->nullOnDelete();
            $t->foreignId('user_relationship_id')->nullable()->constrained('data_object_relationships')->nullOnDelete();
            $t->string('start_field', 100);
            $t->string('end_field', 100)->nullable();
            $t->boolean('contact_specific')->default(false);
            $t->string('type', 10)->default('date');         // date | datetime
            $t->timestamps();
        });

        Schema::create('price_lists', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('form_type', 30)->default('standard');   // standard | formula | location
            $t->json('data');                                    // categories -> items, formulas
            $t->boolean('is_locked')->default(false);
            $t->string('token', 40)->unique();
            $t->timestamps();
        });

        Schema::create('tours', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('archived')->default(false);
            $t->json('email_settings')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('tour_stops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tour_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->json('address')->nullable();
            $t->dateTime('visit_at')->nullable();
            $t->string('status', 20)->default('planned');
            $t->timestamps();
            $t->unique(['tour_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        foreach (['tour_stops', 'tours', 'price_lists', 'schedules', 'workflow_boards', 'contact_attachments', 'contact_relationships', 'contact_relationship_types'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('widgets', fn (Blueprint $t) => [$t->dropConstrainedForeignId('user_id'), $t->dropColumn('position')]);
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('caller_id'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['middle_name', 'title', 'website', 'emails', 'phones']));
    }
};
