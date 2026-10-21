<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Central tables of the onboarding series: the e-mails the platform edits, where each company is, and what was sent to it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_emails', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();                      // welcome, contacts, data, message, automation, team, boards, brand, all_set
            $t->string('milestone', 40)->nullable();              // the step this e-mail nudges towards (null: welcome, all set)
            $t->string('title', 120);                             // the platform's own name for it
            $t->string('subject', 200);
            $t->string('preheader', 200)->nullable();             // the grey line some mail programs show next to the subject
            $t->string('headline', 200);
            $t->longText('body');                                 // rich text with merge fields such as {{owner.first_name}}
            $t->string('button_label', 80)->nullable();
            $t->string('button_path', 200)->nullable();           // where the button leads inside the app, e.g. /contacts?new=1
            $t->string('tip', 400)->nullable();                   // the "did you know" box
            $t->boolean('enabled')->default(true);
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('company_onboardings', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->unique();
            $t->string('state', 20)->default('active')->index();  // active | paused | completed | unsubscribed
            $t->timestamp('started_at');
            $t->json('milestones')->nullable();                   // {key: when it was reached}
            $t->timestamp('last_progress_at')->nullable();        // the last time a new step was reached
            $t->timestamp('last_sent_at')->nullable();
            $t->unsignedSmallInteger('emails_sent')->default(0);
            $t->string('unsubscribe_token', 40)->unique();
            $t->boolean('needs_help')->default(false)->index();
            $t->json('help_reasons')->nullable();                 // [stalled, trial_ending, payment_failed, inactive]
            $t->timestamp('help_since')->nullable();
            $t->timestamp('help_snoozed_until')->nullable();      // "handled": not raised again until then
            $t->text('note')->nullable();                         // the platform team's own note about this company
            $t->timestamps();
        });

        Schema::create('onboarding_sends', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->string('email_key', 40);
            $t->string('to');
            $t->string('subject');
            $t->string('status', 20)->default('sent');            // sent | failed
            $t->string('trigger', 20)->default('auto');           // auto | manual
            $t->text('error')->nullable();
            $t->timestamp('sent_at');
        });

        Schema::table('account_users', function (Blueprint $t) {
            $t->boolean('notify_onboarding')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('account_users', fn (Blueprint $t) => $t->dropColumn('notify_onboarding'));
        Schema::dropIfExists('onboarding_sends');
        Schema::dropIfExists('company_onboardings');
        Schema::dropIfExists('onboarding_emails');
    }
};
