<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Found with `php artisan bench:run` at 300k records:
 *  - the typed field index becomes COVERING for "which records have field X = value" (record_id is now the last
 *    column of each composite index), so filters, board counts and board columns never touch the records table;
 *  - "newest updated first" within an object (boards, recent lists) gets its own index instead of a sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        // New indexes first: on MySQL the field_id foreign key needs an index that starts with field_id at all times,
        // so the old ones can only be dropped once the new ones (which also start with field_id) exist.
        Schema::table('data_object_field_index', function (Blueprint $t) {
            $t->index(['field_id', 'value_string', 'record_id'], 'do_fi_string');
            $t->index(['field_id', 'value_number', 'record_id'], 'do_fi_number');
            $t->index(['field_id', 'value_date', 'record_id'], 'do_fi_date');
        });
        Schema::table('data_object_field_index', function (Blueprint $t) {
            $t->dropIndex(['field_id', 'value_string']);
            $t->dropIndex(['field_id', 'value_number']);
            $t->dropIndex(['field_id', 'value_date']);
        });

        Schema::table('data_object_records', function (Blueprint $t) {
            $t->index(['data_object_id', 'updated_at'], 'do_records_object_updated');
        });

        // records deleted before this release left their index rows behind; the board counts rely on there being none
        DB::table('data_object_field_index')->whereIn('record_id', fn ($q) => $q->select('id')->from('data_object_records')->whereNotNull('deleted_at'))->delete();
    }

    public function down(): void
    {
        Schema::table('data_object_records', fn (Blueprint $t) => $t->dropIndex('do_records_object_updated'));

        Schema::table('data_object_field_index', function (Blueprint $t) {
            $t->index(['field_id', 'value_string']);
            $t->index(['field_id', 'value_number']);
            $t->index(['field_id', 'value_date']);
        });
        Schema::table('data_object_field_index', function (Blueprint $t) {
            $t->dropIndex('do_fi_string');
            $t->dropIndex('do_fi_number');
            $t->dropIndex('do_fi_date');
        });
    }
};
