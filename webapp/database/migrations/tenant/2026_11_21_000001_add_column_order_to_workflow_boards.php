<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The order the board's columns show in (the choices of the key field, as the administrator arranged them). Null: the order of the choices in the field. */
    public function up(): void
    {
        Schema::table('workflow_boards', function (Blueprint $t) {
            $t->json('column_order')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_boards', function (Blueprint $t) {
            $t->dropColumn('column_order');
        });
    }
};
