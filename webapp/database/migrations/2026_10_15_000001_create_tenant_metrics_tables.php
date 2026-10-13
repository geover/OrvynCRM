<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central: what every company did and what it cost the servers, per hour, for the platform dashboard.
     * Worker time is recorded as jobs finish; activity counts and a snapshot of totals are collected hourly inside each company.
     */
    public function up(): void
    {
        Schema::create('tenant_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->dateTime('hour');                                       // start of the hour (UTC)
            // background work (recorded by the queue listener)
            $table->unsignedInteger('jobs')->default(0);
            $table->unsignedBigInteger('job_ms')->default(0);
            $table->unsignedInteger('jobs_failed')->default(0);
            $table->unsignedInteger('automation_jobs')->default(0);
            $table->unsignedBigInteger('automation_ms')->default(0);
            $table->unsignedInteger('message_jobs')->default(0);
            $table->unsignedBigInteger('message_ms')->default(0);
            // activity (collected hourly inside the company)
            $table->unsignedInteger('activities')->default(0);
            $table->unsignedInteger('new_contacts')->default(0);
            $table->unsignedInteger('automation_runs')->default(0);
            $table->unsignedInteger('automation_runs_failed')->default(0);
            $table->unsignedInteger('emails_sent')->default(0);
            $table->unique(['tenant_id', 'hour']);
            $table->index('hour');
        });

        Schema::create('tenant_stats', function (Blueprint $table) {
            $table->string('tenant_id', 64)->primary();
            $table->unsignedBigInteger('contacts')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->unsignedBigInteger('records')->default(0);
            $table->unsignedInteger('automations_total')->default(0);
            $table->unsignedInteger('automations_active')->default(0);
            $table->unsignedBigInteger('activities_total')->default(0);
            $table->decimal('db_mb', 12, 2)->default(0);
            $table->json('top_automations')->nullable();                   // the heaviest automations of the last 24 hours
            $table->timestamp('collected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_stats');
        Schema::dropIfExists('tenant_metrics');
    }
};
