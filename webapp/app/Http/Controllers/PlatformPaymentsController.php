<?php

namespace App\Http\Controllers;

use App\Integrations\Stripe\StripeClient;
use App\Models\CompanySubscription;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Subscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Platform > Settings > Payments: the Stripe account companies pay through, and a Stripe price for every tier. */
class PlatformPaymentsController extends Controller
{
    public function show(PaymentSettings $payments, Plans $plans): Response
    {
        return Inertia::render('Platform/SettingsPayments', [
            'settings' => $payments->current(),
            'tiers' => collect($plans->tiers())->map(fn (array $t) => [
                'tier' => $t['tier'], 'name' => $t['name'], 'price' => $t['price'], 'synced' => (bool) $payments->priceId($t['tier']),
            ])->values(),
            'money' => $plans->money(),
            'subscriptions' => CompanySubscription::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function save(Request $request, PaymentSettings $payments): RedirectResponse
    {
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'require_payment' => ['required', 'boolean'],
            'secret_key' => ['nullable', 'string', 'max:300', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'clear_key' => ['sometimes', 'boolean'],
            'webhook_secret' => ['nullable', 'string', 'max:300', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            'clear_webhook' => ['sometimes', 'boolean'],
        ], ['secret_key.regex' => 'A secret key looks like sk_test_… or sk_live_… (Stripe: Developers, API keys).', 'webhook_secret.regex' => 'A signing secret looks like whsec_… (Stripe: Developers, Webhooks).']);
        $payments->save($d);

        return back()->with('success', $d['enabled'] ? 'Saved. Companies can now pay by card on Stripe.' : 'Saved. Online payment is off.');
    }

    /** Ask Stripe who the key belongs to: proves the key is accepted. */
    public function test(StripeClient $stripe, PaymentSettings $payments): RedirectResponse
    {
        if (! $payments->secretKey()) {
            return back()->withErrors(['test' => 'Save the key and switch payments on first.']);
        }

        try {
            $account = $stripe->get('account');
        } catch (Throwable $e) {
            return back()->withErrors(['test' => str($e->getMessage())->squish()->limit(240)->value()]);
        }
        $name = $account['settings']['dashboard']['display_name'] ?? $account['business_profile']['name'] ?? $account['id'] ?? 'your account';

        return back()->with('success', "Stripe accepted the key ({$name}, {$payments->mode()} mode).");
    }

    /** Create or renew the Stripe price of every tier from the prices set on Plans & trial. */
    public function syncPrices(Subscriptions $subscriptions): RedirectResponse
    {
        if (! $subscriptions->available()) {
            return back()->withErrors(['prices' => 'Save the key and switch payments on first.']);
        }

        try {
            $r = $subscriptions->syncPrices();
        } catch (Throwable $e) {
            return back()->withErrors(['prices' => str($e->getMessage())->squish()->limit(240)->value()]);
        }
        $made = count($r['created']);

        return back()->with('success', $made ? "{$made} Stripe ".($made === 1 ? 'price' : 'prices').' created or renewed.' : 'The Stripe prices are already up to date.'.($r['skipped'] ? ' Tiers without a price here were skipped.' : ''));
    }
}
