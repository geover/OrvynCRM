<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central: how many people each company may have, and the requests for more (billing comes later). */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('max_users')->default(5);
        });

        Schema::create('seat_requests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->unsignedBigInteger('requested_by')->nullable();           // the account (person) who asked
            $table->string('requester_name');
            $table->string('requester_email');
            $table->unsignedInteger('current_limit');
            $table->unsignedInteger('requested_limit');                       // the total they ask for
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending')->index();        // pending | approved | declined
            $table->unsignedInteger('granted_limit')->nullable();
            $table->text('response')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_requests');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('max_users');
        });
    }
};
