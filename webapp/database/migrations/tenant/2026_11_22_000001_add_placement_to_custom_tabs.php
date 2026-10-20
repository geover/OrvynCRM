<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A custom tab is either a tab on every contact ("contact") or a page in the main left-hand menu ("sidebar"), and can be limited to chosen roles, groups or people. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tabs', function (Blueprint $t) {
            $t->string('placement', 20)->default('contact')->after('type');
            $t->string('icon', 40)->nullable()->after('placement');
            $t->json('audience')->nullable()->after('permissions');       // null = everyone; otherwise {roles: [...], templates: [ids], users: [ids]}
        });
    }

    public function down(): void
    {
        Schema::table('custom_tabs', function (Blueprint $t) {
            $t->dropColumn(['placement', 'icon', 'audience']);
        });
    }
};
