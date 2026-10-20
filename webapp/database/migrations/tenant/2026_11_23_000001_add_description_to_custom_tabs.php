<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A line under the title of a custom tab, in the administrator's own words, saying what the tab is for. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tabs', function (Blueprint $t) {
            $t->string('description', 300)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('custom_tabs', function (Blueprint $t) {
            $t->dropColumn('description');
        });
    }
};
