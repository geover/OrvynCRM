<?php

namespace App\Http\Controllers;

use App\Models\CompanyManualInvoice;
use App\Models\Tenant;
use App\Services\Tenancy\AccountStanding;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The invoices the platform sends to a company it bills itself (outside Stripe): add one, mark it paid, delete it. What is overdue is chased from Account standing. */
class PlatformManualInvoiceController extends Controller
{
    public function store(Request $request, Tenant $tenant, Plans $plans, AccountStanding $standing): RedirectResponse
    {
        $d = $request->validate([
            'number' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'currency' => ['nullable', Rule::in(Pricing::CURRENCIES)],
            'issued_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:issued_on'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['due_on.after_or_equal' => 'The due date cannot be before the invoice date.']);
        $currency = strtoupper($d['currency'] ?? $plans->money()['currency']);

        CompanyManualInvoice::create([
            'tenant_id' => $tenant->getTenantKey(), 'number' => $d['number'] ?? null, 'amount_due' => (int) round($d['amount'] * $standing->factor($currency)),
            'currency' => strtolower($currency), 'issued_on' => $d['issued_on'], 'due_on' => $d['due_on'], 'note' => $d['note'] ?? null,
            'created_by' => $request->attributes->get('platform_identity')?->id,
        ]);

        return back()->with('success', 'The invoice was added.');
    }

    public function paid(Request $request, Tenant $tenant, CompanyManualInvoice $invoice): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->getTenantKey(), 404);
        $d = $request->validate(['paid_on' => ['nullable', 'date', 'before_or_equal:today']]);
        $invoice->update(['paid_on' => $d['paid_on'] ?? now()->toDateString()]);

        return back()->with('success', 'Marked as paid.');
    }

    public function destroy(Tenant $tenant, CompanyManualInvoice $invoice): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->getTenantKey(), 404);
        $invoice->delete();

        return back()->with('success', 'The invoice was removed.');
    }
}
