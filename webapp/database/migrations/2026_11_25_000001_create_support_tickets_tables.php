<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Support tickets: a tracked problem reported by a company's administrators and worked on by the platform's, apart from the conversations in Messages. Central tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->string('subject', 200);
            $t->string('category', 20)->default('other');           // bug | question | request | billing | other
            $t->string('priority', 20)->default('normal');          // low | normal | high | urgent
            $t->string('status', 20)->default('open')->index();     // open (the platform's turn) | waiting (the company's turn) | resolved | closed
            $t->unsignedBigInteger('opened_by_account_id')->nullable();
            $t->string('opened_by_name');
            $t->string('opened_by_email');
            $t->unsignedBigInteger('assignee_id')->nullable()->index();   // a platform administrator
            $t->unsignedBigInteger('thread_id')->nullable()->index();     // the Messages conversation it came from
            $t->json('context')->nullable();                        // the page, browser and role of whoever reported it
            $t->boolean('unread_platform')->default(true);
            $t->boolean('unread_tenant')->default(false);
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedSmallInteger('reopened_count')->default(0);
            $t->timestamps();
        });

        Schema::create('ticket_replies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id')->index();
            $t->string('kind', 10)->default('reply');               // reply | note (the platform's own, never shown to the company) | event (what changed)
            $t->string('side', 10);                                 // tenant | platform | system | internal (an event only the platform sees)
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('name');
            $t->string('email')->nullable();
            $t->longText('body');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('ticket_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reply_id')->index();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 80);
            $t->unsignedInteger('size')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::table('message_threads', fn (Blueprint $t) => $t->unsignedBigInteger('ticket_id')->nullable()->after('tenant_id'));
        Schema::table('account_users', fn (Blueprint $t) => $t->boolean('notify_tickets')->default(true));
    }

    public function down(): void
    {
        Schema::table('account_users', fn (Blueprint $t) => $t->dropColumn('notify_tickets'));
        Schema::table('message_threads', fn (Blueprint $t) => $t->dropColumn('ticket_id'));
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
    }
};
