<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Central: what Stripe invoiced each company and how it went (the payment history), and the reminders sent about money (by hand or automatically). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->string('stripe_invoice_id')->unique();
            $table->string('number')->nullable();
            $table->string('status', 20)->index();                    // paid | failed | open | void | uncollectible
            $table->unsignedBigInteger('amount_due')->default(0);     // in the smallest unit of the currency
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('hosted_url')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();               // the first failed attempt of this invoice
            $table->timestamps();
            $table->index(['tenant_id', 'issued_at']);
        });

        Schema::create('company_payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->string('kind', 30);                               // manual | failed_3 | failed_7
            $table->unsignedBigInteger('sent_by')->nullable();        // the platform administrator, for a manual one
            $table->string('standing', 30)->nullable();               // what the company's standing was when it was sent
            $table->string('subject');
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payment_reminders');
        Schema::dropIfExists('company_invoices');
    }
};
