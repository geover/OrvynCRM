<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Found by `php artisan bench:run` on MySQL at 100k contacts / 300k records:
 *  - "sort contacts by company" scanned and filesorted the whole table; this index serves the exact ORDER BY
 *    (company_name ASC, id DESC) so only the page is read;
 *  - counting an object's records (dashboard, boards) read ~300k wide rows; this narrow index answers it on its own.
 * Written as raw statements because the schema builder cannot declare a descending index part.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index contacts_company_name_id_index on contacts (company_name, id desc)');
        DB::statement('create index do_records_object_deleted on data_object_records (data_object_id, deleted_at)');
    }

    public function down(): void
    {
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        DB::statement($mysql ? 'drop index contacts_company_name_id_index on contacts' : 'drop index contacts_company_name_id_index');
        DB::statement($mysql ? 'drop index do_records_object_deleted on data_object_records' : 'drop index do_records_object_deleted');
    }
};
