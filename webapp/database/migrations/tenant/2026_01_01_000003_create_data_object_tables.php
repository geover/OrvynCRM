<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces Macanta's ConnectedData* EAV tables. A record keeps ALL of its values in one JSON
 * column (one row read instead of N value rows); only fields flagged `is_indexed` are mirrored
 * into data_object_field_index as typed, indexed columns for fast filtering and sorting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_objects', function (Blueprint $t) {
            $t->id();
            $t->string('legacy_id', 50)->nullable()->unique();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->string('icon', 50)->nullable();
            $t->json('settings')->nullable();   // permissions, workflow board, scheduler, ...
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });

        Schema::create('data_object_fields', function (Blueprint $t) {
            $t->id();
            $t->string('legacy_id', 50)->nullable()->unique();
            $t->foreignId('data_object_id')->constrained()->cascadeOnDelete();
            $t->string('key', 100);
            $t->string('label');
            $t->string('type', 30)->default('text'); // text,textarea,number,date,datetime,email,url,select,multiselect,checkbox,file
            $t->json('options')->nullable();          // choices, placeholder, helper text, default, section ...
            $t->boolean('is_required')->default(false);
            $t->boolean('is_indexed')->default(false);
            $t->boolean('show_in_list')->default(false);
            $t->boolean('contact_specific')->default(false); // value differs per attached contact
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
            $t->unique(['data_object_id', 'key']);
        });

        Schema::create('data_object_relationships', function (Blueprint $t) {
            $t->id();
            $t->string('legacy_id', 50)->nullable()->unique();
            $t->string('label');
            $t->text('description')->nullable();
            $t->timestamps();
        });

        Schema::create('data_object_relationship_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('data_object_id')->constrained()->cascadeOnDelete();
            $t->foreignId('relationship_id')->constrained('data_object_relationships')->cascadeOnDelete();
            $t->boolean('is_exclusive')->default(false);
            $t->unsignedInteger('max_contacts')->nullable();
            $t->boolean('auto_assign_user')->default(false);
            $t->boolean('auto_assign_contact')->default(false);
            $t->unique(['data_object_id', 'relationship_id'], 'do_rel_rules_unique');
        });

        Schema::create('data_object_records', function (Blueprint $t) {
            $t->id();
            $t->string('legacy_id', 50)->nullable()->unique();
            $t->foreignId('data_object_id')->constrained()->cascadeOnDelete();
            $t->json('data');                        // {field_key: value}
            $t->longText('search_text')->nullable(); // flattened values for FULLTEXT search
            $t->string('status', 20)->default('active')->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['data_object_id', 'status', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE data_object_records ADD FULLTEXT do_records_search (search_text)');
        }

        Schema::create('data_object_field_index', function (Blueprint $t) {
            $t->foreignId('record_id')->constrained('data_object_records')->cascadeOnDelete();
            $t->foreignId('field_id')->constrained('data_object_fields')->cascadeOnDelete();
            $t->unsignedBigInteger('contact_id')->nullable()->index(); // contact-specific values
            $t->string('value_string', 191)->nullable();
            $t->decimal('value_number', 20, 4)->nullable();
            $t->dateTime('value_date')->nullable();
            $t->index(['field_id', 'value_string']);
            $t->index(['field_id', 'value_number']);
            $t->index(['field_id', 'value_date']);
            $t->index('record_id');
        });

        Schema::create('data_object_record_contact', function (Blueprint $t) {
            $t->id();
            $t->foreignId('record_id')->constrained('data_object_records')->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('relationship_id')->constrained('data_object_relationships')->cascadeOnDelete();
            $t->json('contact_values')->nullable();   // contact-specific field values
            $t->timestamps();
            $t->unique(['record_id', 'contact_id', 'relationship_id'], 'do_rc_unique');
            $t->index(['contact_id', 'relationship_id']);
        });

        Schema::create('data_object_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('record_id')->constrained('data_object_records')->cascadeOnDelete();
            $t->string('disk', 30)->default('s3');
            $t->string('path');
            $t->string('filename');
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamps();
        });

        Schema::create('saved_queries', function (Blueprint $t) {  // Macanta "ConnectedDataQuery"
            $t->id();
            $t->string('legacy_id', 50)->nullable()->unique();
            $t->string('name');
            $t->string('scope', 30)->default('data_object'); // contact | data_object
            $t->foreignId('data_object_id')->nullable()->constrained()->cascadeOnDelete();
            $t->json('definition');                          // filter tree
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'saved_queries', 'data_object_attachments', 'data_object_record_contact', 'data_object_field_index',
            'data_object_records', 'data_object_relationship_rules', 'data_object_relationships',
            'data_object_fields', 'data_objects',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
