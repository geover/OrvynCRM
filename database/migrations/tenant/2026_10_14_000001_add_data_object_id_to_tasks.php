<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The data object whose automation created the task (null: a person created it). The overdue digest can then skip a paused data object. */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->unsignedBigInteger('data_object_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropColumn('data_object_id');
        });
    }
};
