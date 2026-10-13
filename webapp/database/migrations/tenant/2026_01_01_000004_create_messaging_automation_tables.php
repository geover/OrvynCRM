<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('group')->nullable()->index();
            $t->string('subject')->nullable();
            $t->longText('html');
            $t->json('design')->nullable();
            $t->timestamps();
        });

        Schema::create('email_messages', function (Blueprint $t) {  // queue and history in one table
            $t->id();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('data_object_record_id')->nullable()->constrained('data_object_records')->nullOnDelete();
            $t->string('status', 20)->default('queued')->index(); // queued,sending,sent,failed,suppressed
            $t->string('subject');
            $t->string('from_email');
            $t->string('from_name')->nullable();
            $t->json('to');
            $t->json('cc')->nullable();
            $t->json('bcc')->nullable();
            $t->longText('html');
            $t->json('attachments')->nullable();
            $t->string('provider', 30)->nullable();
            $t->string('provider_message_id')->nullable()->index();
            $t->string('tracker_id', 40)->unique();
            $t->unsignedInteger('open_count')->default(0);
            $t->unsignedInteger('click_count')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'scheduled_at']);
            $t->index(['contact_id', 'created_at']);
        });

        Schema::create('sms_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('direction', 10)->default('out');
            $t->string('status', 20)->default('queued')->index();
            $t->string('from_number', 30)->nullable();
            $t->string('to_number', 30);
            $t->text('body');
            $t->string('provider', 30)->default('twilio');
            $t->string('provider_message_id')->nullable()->index();
            $t->text('error')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['contact_id', 'created_at']);
        });

        Schema::create('email_suppressions', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('reason', 50)->nullable();
            $t->timestamps();
        });

        Schema::create('automations', function (Blueprint $t) {
            $t->id();
            $t->string('legacy_id', 50)->nullable()->unique();
            $t->string('name');
            $t->foreignId('data_object_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('active')->index();
            $t->json('conditions');               // trigger condition tree
            $t->json('actions');                  // ordered action list
            $t->string('schedule', 30)->default('on_change'); // on_change | every_minute | hourly | daily
            $t->timestamp('last_run_at')->nullable();
            $t->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('data_object_record_id')->nullable()->constrained('data_object_records')->cascadeOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('pending')->index();
            $t->json('result')->nullable();
            $t->timestamp('held_until')->nullable();
            $t->timestamps();
            // Replaces ConnectedDataAutomationTriggerRecord: a trigger fires once per subject
            $t->unique(['automation_id', 'data_object_record_id', 'contact_id'], 'automation_run_once');
        });

        Schema::create('forms', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36)->unique();
            $t->string('name');
            $t->foreignId('data_object_id')->nullable()->constrained()->nullOnDelete();
            $t->json('schema');
            $t->json('settings')->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $t->json('payload');
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });

        Schema::create('widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type', 30);               // email | sms | count | list | chart
            $t->foreignId('saved_query_id')->nullable()->constrained()->nullOnDelete();
            $t->json('settings')->nullable();
            $t->timestamps();
        });

        Schema::create('custom_tabs', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('type', 30)->default('iframe');
            $t->text('content');
            $t->json('permissions')->nullable();
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $t) {  // inbound + outbound ("RestHook")
            $t->id();
            $t->string('name');
            $t->string('direction', 10);          // in | out
            $t->string('url')->nullable();
            $t->string('secret', 80)->nullable();
            $t->json('events')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $t->string('event', 80);
            $t->json('payload');
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });

        Schema::create('integrations', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 30)->unique(); // keap, twilio, nylas, sendgrid, chargebee, infobip, ses
            $t->text('credentials')->nullable();  // encrypted cast
            $t->json('settings')->nullable();
            $t->boolean('is_enabled')->default(false);
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('data_transfers', function (Blueprint $t) {  // import + export requests
            $t->id();
            $t->string('direction', 10);
            $t->string('resource', 40);
            $t->string('status', 20)->default('pending')->index();
            $t->string('file_path')->nullable();
            $t->json('options')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->text('error')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('notifications_feed', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('url')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'notifications_feed', 'data_transfers', 'integrations', 'webhook_deliveries', 'webhook_endpoints',
            'custom_tabs', 'widgets', 'form_submissions', 'forms', 'automation_runs', 'automations',
            'email_suppressions', 'sms_messages', 'email_messages', 'email_templates',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
