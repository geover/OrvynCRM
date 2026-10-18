<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Central: the invoices the platform sends to companies it bills itself (not through Stripe), so what is paid, due or overdue can be seen and chased. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_manual_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->string('number', 60)->nullable();
            $table->unsignedBigInteger('amount_due');                 // in the smallest unit of the currency
            $table->string('currency', 3)->default('usd');
            $table->date('issued_on');
            $table->date('due_on')->index();
            $table->date('paid_on')->nullable();
            $table->string('note', 300)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();     // the platform administrator
            $table->timestamps();
            $table->index(['tenant_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_manual_invoices');
    }
};
