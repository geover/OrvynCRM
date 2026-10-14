<?php

namespace App\Services\Tenancy;

use App\Integrations\Stripe\StripeClient;
use App\Models\CompanyBilling;
use App\Models\CompanySubscription;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\NoticeMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A company's subscription on Stripe. The company picks a tier and pays on Stripe's own page (Checkout); managing the card and
 * invoices happens in Stripe's Customer Portal; Stripe reports every change back by webhook, which is the only thing that
 * updates what we store (status, tier, renewal date, card brand and last four digits). Card numbers never reach this application.
 */
class Subscriptions
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly PaymentSettings $settings,
        private readonly Plans $plans,
        private readonly PlatformNotifier $notifier,
        private readonly CompanyAdminNotices $notices,
    ) {}

    /** Can companies pay online (the platform switched Stripe on and saved a key)? */
    public function available(): bool
    {
        return $this->stripe->configured();
    }

    public function forTenant(Tenant $tenant): ?CompanySubscription
    {
        return CompanySubscription::where('tenant_id', $tenant->getTenantKey())->first();
    }

    /** @return array<string, mixed> what the billing page shows about paying */
    public function summary(Tenant $tenant): array
    {
        $s = $this->forTenant($tenant);

        return [
            'available' => $this->available(),
            'status' => $s?->status,
            'paying' => (bool) $s?->isGood(),
            'tier' => $s?->tier,
            'renews_at' => $s?->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $s?->cancel_at_period_end,
            'card' => $s?->card_last4 ? ['brand' => $s->card_brand, 'last4' => $s->card_last4] : null,
            'can_manage' => (bool) $s?->stripe_customer_id,
            'ended' => $s?->status === 'canceled',
            'canceled_by' => $s?->canceled_by,
            'cancel_reason' => $s?->cancel_reason,
            'canceled_at' => $s?->canceled_at?->toIso8601String(),
            // after a cancellation the platform has to allow subscribing again
            'can_subscribe' => $s === null || $s->status !== 'canceled' || $s->resubscribe_enabled,
            'resubscribe_enabled' => (bool) $s?->resubscribe_enabled,
            'resubscribe_requested' => SubscriptionRequest::pending()->where('tenant_id', $tenant->getTenantKey())->exists(),
        ];
    }

    // ---- the platform: a Stripe price for every tier -----------------------------------------------------------------------------------

    /**
     * Make (or renew) the Stripe product and price of every tier that has a price here. A price that has not changed is left alone;
     * a changed one gets a new Stripe price and the old one is archived (companies already subscribed keep what they signed up for).
     *
     * @return array{created: list<int>, unchanged: list<int>, skipped: list<int>}
     *
     * @throws RuntimeException
     */
    public function syncPrices(): array
    {
        $money = $this->plans->money();
        $out = ['created' => [], 'unchanged' => [], 'skipped' => []];

        foreach ($this->plans->tiers() as $tier) {
            $n = $tier['tier'];
            if ($tier['price'] === null) {
                $out['skipped'][] = $n;

                continue;
            }
            // a renamed tier renames its product on Stripe too
            if ($product = $this->settings->productId($n)) {
                $this->settings->productName($n) !== $tier['name'] && $this->renameProduct($n, $product, $tier['name']);
            }
            $amount = $this->minorUnits($tier['price'], $money['currency']);
            $signature = "{$amount}|{$money['currency']}|{$money['period']}";
            if ($this->settings->priceId($n) && $this->settings->priceSignature($n) === $signature) {
                $out['unchanged'][] = $n;

                continue;
            }

            $product = $this->settings->productId($n) ?? $this->stripe->post('products', ['name' => 'OrvynCRM '.$tier['name'], 'metadata' => ['tier' => $n]])['id'];
            $price = $this->stripe->post('prices', [
                'product' => $product, 'unit_amount' => $amount, 'currency' => strtolower($money['currency']),
                'recurring' => ['interval' => $money['period']], 'metadata' => ['tier' => $n],
            ])['id'];

            if ($old = $this->settings->priceId($n)) {
                try {
                    $this->stripe->post("prices/{$old}", ['active' => 'false']);
                } catch (Throwable) {
                    // an old price that cannot be archived does no harm
                }
            }
            $this->settings->savePrice($n, $product, $price, $signature);
            $this->settings->saveProductName($n, $tier['name']);
            $out['created'][] = $n;
        }

        return $out;
    }

    /** What a tier's price signature should be for Stripe (amount in the smallest unit, currency, interval). */
    private function signatureFor(array $tier): ?string
    {
        if ($tier['price'] === null) {
            return null;
        }
        $money = $this->plans->money();

        return $this->minorUnits($tier['price'], $money['currency']).'|'.$money['currency'].'|'.$money['period'];
    }

    /**
     * Where a tier's price stands against Stripe: none (no price here, not sold online), synced (Stripe has this price), outdated (the price
     * or currency changed here since Stripe's was made) or missing (a price here that Stripe does not have yet).
     *
     * @return 'none'|'synced'|'outdated'|'missing'
     */
    public function stateOf(int $tier): string
    {
        $t = $this->plans->tier($tier);
        if ($t['price'] === null) {
            return 'none';
        }
        if (! $this->settings->priceId($tier)) {
            return 'missing';
        }

        return $this->settings->priceSignature($tier) === $this->signatureFor($t) ? 'synced' : 'outdated';
    }

    /**
     * Running subscriptions that are charged on an older Stripe price than their tier's current one (the price changed after they subscribed).
     * A subscription whose price is not known yet is looked up on Stripe.
     *
     * @return list<array{row: CompanySubscription, company: string, tier: int, from: string|null, to: string}>
     */
    public function outdatedSubscribers(): array
    {
        $out = [];
        $names = Tenant::pluck('name', 'id');
        foreach (CompanySubscription::whereIn('status', CompanySubscription::GOOD)->whereNotNull('stripe_subscription_id')->get() as $row) {
            $current = $row->tier ? $this->settings->priceId((int) $row->tier) : null;
            if (! $current) {
                continue;
            }
            if (! $row->stripe_price_id) {
                try {
                    $price = $this->stripe->get("subscriptions/{$row->stripe_subscription_id}")['items']['data'][0]['price']['id'] ?? null;
                    $price && $row->update(['stripe_price_id' => $price]);
                } catch (Throwable) {
                    continue;
                }
            }
            if ($row->stripe_price_id && $row->stripe_price_id !== $current) {
                $out[] = ['row' => $row, 'company' => $names[$row->tenant_id] ?? 'Deleted company', 'tier' => (int) $row->tier, 'from' => $row->stripe_price_id, 'to' => $current];
            }
        }

        return $out;
    }

    /**
     * Move the subscribers who are on an old price to the current one, from their next renewal (nothing is charged or credited now).
     *
     * @return array{moved: int, failed: list<string>}
     */
    public function moveSubscribers(bool $tell): array
    {
        $this->ensureAvailable();
        $moved = 0;
        $failed = [];
        foreach ($this->outdatedSubscribers() as $o) {
            $row = $o['row'];
            try {
                $item = $this->stripe->get("subscriptions/{$row->stripe_subscription_id}")['items']['data'][0]['id'] ?? null;
                $this->stripe->post("subscriptions/{$row->stripe_subscription_id}", ['items' => [['id' => $item, 'price' => $o['to']]], 'proration_behavior' => 'none']);
                $row->update(['stripe_price_id' => $o['to']]);
                $moved++;
                if ($tell && ($tenant = Tenant::find($row->tenant_id))) {
                    $plan = $this->plans->tier($o['tier']);
                    $money = $this->plans->money();
                    $this->notices->tell($tenant, "The price of {$plan['name']} has changed", [
                        "From your next renewal your plan {$plan['name']} costs ".number_format((float) $plan['price'], 2)." {$money['currency']} per {$money['period']}.",
                        'Nothing is charged or credited now. You can see your subscription and invoices on the Billing & plan page.',
                    ]);
                }
            } catch (Throwable $e) {
                $failed[] = $o['company'].': '.str($e->getMessage())->squish()->limit(100)->value();
            }
        }

        return ['moved' => $moved, 'failed' => $failed];
    }

    private function renameProduct(int $tier, string $product, string $name): void
    {
        try {
            $this->stripe->post("products/{$product}", ['name' => 'OrvynCRM '.$name]);
            $this->settings->saveProductName($tier, $name);
        } catch (Throwable) {
            // the name on Stripe is only a label: try again on the next sync
        }
    }

    /** Stripe wants the smallest unit of the currency (cents). A few currencies have none. */
    private function minorUnits(float $price, string $currency): int
    {
        return in_array($currency, ['JPY'], true) ? (int) round($price) : (int) round($price * 100);
    }

    // ---- the company ------------------------------------------------------------------------------------------------------------------

    /**
     * A Stripe Checkout page for subscribing to a tier. During a free trial that still has at least two days left the card is taken
     * now and the first charge waits for the end of the trial.
     *
     * @throws ValidationException
     * @throws RuntimeException
     */
    public function checkoutUrl(Tenant $tenant, int $tier, User $by): string
    {
        $this->ensureAvailable();
        $priceId = $this->settings->priceId($tier);
        if (! $priceId || $this->plans->price($tier) === null) {
            throw ValidationException::withMessages(['tier' => 'This plan cannot be bought online yet. Ask the platform for it.']);
        }
        $current = $this->forTenant($tenant);
        if ($current?->status === 'canceled' && ! $current->resubscribe_enabled) {
            throw ValidationException::withMessages(['tier' => 'You cancelled your subscription. Ask the platform to let you subscribe again, and you will be told when you can.']);
        }
        if ($this->forTenant($tenant)?->isGood()) {
            throw ValidationException::withMessages(['tier' => 'You already have a subscription. Change the plan or the card from the billing page.']);
        }

        $existing = $this->forTenant($tenant)?->stripe_customer_id;
        $billing = CompanyBilling::where('tenant_id', $tenant->getTenantKey())->first();
        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => url('/billing?subscribed=1'),
            'cancel_url' => url('/billing'),
            'client_reference_id' => $tenant->getTenantKey(),
            'billing_address_collection' => 'required',
            'allow_promotion_codes' => 'true',
            'metadata' => ['tenant_id' => $tenant->getTenantKey(), 'tier' => $tier],
            'subscription_data' => ['metadata' => ['tenant_id' => $tenant->getTenantKey(), 'tier' => $tier]],
        ];
        $existing ? $params['customer'] = $existing : $params['customer_email'] = $billing?->contact_email ?: $by->email;

        $trialEnd = $tenant->trial_ends_at;
        if ($trialEnd && $trialEnd->greaterThan(now()->addDays(2)) && $trialEnd->lessThan(now()->addDays(700))) {
            $params['subscription_data']['trial_end'] = $trialEnd->timestamp;
        }

        return (string) $this->stripe->post('checkout/sessions', $params)['url'];
    }

    /** Stripe's Customer Portal: change the card, see and download invoices, cancel. */
    public function portalUrl(Tenant $tenant): string
    {
        $this->ensureAvailable();
        $customer = $this->forTenant($tenant)?->stripe_customer_id;
        if (! $customer) {
            throw ValidationException::withMessages(['tier' => 'There is nothing to manage yet: subscribe first.']);
        }

        return (string) $this->stripe->post('billing_portal/sessions', ['customer' => $customer, 'return_url' => url('/billing')])['url'];
    }

    /**
     * A company with a running subscription changes tier: Stripe moves it to the new price (charging or crediting the difference
     * for the rest of the period). Returns false when there is no subscription to change (the tier is then only changed here).
     *
     * @throws ValidationException
     * @throws RuntimeException
     */
    public function changeTier(Tenant $tenant, int $tier): bool
    {
        $sub = $this->forTenant($tenant);
        if (! $sub || ! $sub->isGood() || ! $sub->stripe_subscription_id || ! $this->available()) {
            return false;
        }
        $priceId = $this->settings->priceId($tier);
        if (! $priceId) {
            throw ValidationException::withMessages(['tier' => 'This plan cannot be bought online yet. Ask the platform for it.']);
        }

        $item = $this->stripe->get("subscriptions/{$sub->stripe_subscription_id}")['items']['data'][0]['id'] ?? null;
        $this->stripe->post("subscriptions/{$sub->stripe_subscription_id}", [
            'items' => [['id' => $item, 'price' => $priceId]], 'proration_behavior' => 'create_prorations', 'metadata' => ['tier' => $tier],
        ]);
        $sub->update(['tier' => $tier, 'stripe_price_id' => $priceId]);

        return true;
    }

    // ---- cancelling and subscribing again ---------------------------------------------------------------------------------------------

    /**
     * Cancel the subscription: at the end of the period already paid for (the company keeps everything until then), or at once.
     * When it has ended the company goes back to the first tier and has to ask the platform before it can subscribe again.
     *
     * @param  'company'|'platform'  $by
     *
     * @throws ValidationException
     * @throws RuntimeException
     */
    public function cancel(Tenant $tenant, bool $immediately, ?string $reason, string $by, bool $tell = true): CompanySubscription
    {
        $sub = $this->forTenant($tenant);
        if (! $sub || ! $sub->isGood() || ! $sub->stripe_subscription_id) {
            throw ValidationException::withMessages(['subscription' => 'There is no running subscription to cancel.']);
        }
        $this->ensureAvailable();

        $immediately
            ? $this->stripe->request('DELETE', "subscriptions/{$sub->stripe_subscription_id}")
            : $this->stripe->post("subscriptions/{$sub->stripe_subscription_id}", ['cancel_at_period_end' => 'true']);

        $sub->update([
            'cancel_reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
            'canceled_by' => $by, 'canceled_at' => now(), 'cancel_at_period_end' => ! $immediately,
        ]);

        if ($immediately) {
            $sub->update(['status' => 'canceled']);
            $this->ended($tenant, $sub, $tell && $by === 'platform');
        } else {
            $end = $sub->current_period_end ? $sub->current_period_end->format('F j, Y') : 'the end of the current period';
            $this->announceCancellation($tenant, $sub, $by, $tell, "The subscription ends on {$end}.");
        }

        return $sub->fresh();
    }

    /** Take a cancellation back while the subscription is still running: it carries on as before. */
    public function resume(Tenant $tenant, string $by): CompanySubscription
    {
        $sub = $this->forTenant($tenant);
        if (! $sub || ! $sub->isGood() || ! $sub->cancel_at_period_end || ! $sub->stripe_subscription_id) {
            throw ValidationException::withMessages(['subscription' => 'This subscription is not waiting to be cancelled.']);
        }
        $this->ensureAvailable();
        $this->stripe->post("subscriptions/{$sub->stripe_subscription_id}", ['cancel_at_period_end' => 'false']);
        $sub->update(['cancel_at_period_end' => false, 'cancel_reason' => null, 'canceled_by' => null, 'canceled_at' => null]);

        if ($by === 'platform') {
            $this->notices->tell($tenant, "The cancellation of {$tenant->name} was withdrawn", ['Your subscription carries on as before.', 'The platform withdrew the cancellation: nothing changes and you stay on your plan.']);
        }

        return $sub->fresh();
    }

    /** The company asks the platform to be allowed to subscribe again. */
    public function requestResubscribe(User $by, Tenant $tenant, ?int $tier, ?string $message): SubscriptionRequest
    {
        $sub = $this->forTenant($tenant);
        if (! $sub || $sub->status !== 'canceled') {
            throw ValidationException::withMessages(['message' => 'Only a company whose subscription has ended can ask to subscribe again.']);
        }
        if ($sub->resubscribe_enabled) {
            throw ValidationException::withMessages(['message' => 'You can subscribe again right now.']);
        }
        if (SubscriptionRequest::pending()->where('tenant_id', $tenant->getTenantKey())->exists()) {
            throw ValidationException::withMessages(['message' => 'You already asked. The platform will answer soon.']);
        }

        $request = SubscriptionRequest::create([
            'tenant_id' => $tenant->getTenantKey(), 'requested_by' => $by->central_user_id, 'requester_name' => $by->name, 'requester_email' => $by->email,
            'tier' => $tier && array_key_exists($tier, config('plans.tiers')) ? $tier : null, 'message' => $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 1000) : null,
        ]);
        $plan = $request->tier ? $this->plans->tier($request->tier)['name'] : 'no plan chosen';
        $this->notifier->mail(
            PlatformNotifier::REQUESTS,
            "{$tenant->name} wants to subscribe again",
            "{$by->name} ({$by->email}) asks to subscribe again for {$tenant->name} ({$plan}).".($request->message ? "\n\nMessage:\n{$request->message}" : '')."\n\nEnable it under Platform > Requests: ".url('/platform/seat-requests'),
        );

        return $request;
    }

    /**
     * The platform lets the company subscribe again (what a request asks for; it can also be done without one). The company is told.
     */
    public function enableResubscribe(Tenant $tenant, ?string $response = null): void
    {
        $sub = $this->forTenant($tenant);
        if (! $sub || $sub->status !== 'canceled') {
            throw ValidationException::withMessages(['subscription' => 'Only a company whose subscription has ended needs this.']);
        }
        $sub->update(['resubscribe_enabled' => true]);
        SubscriptionRequest::pending()->where('tenant_id', $tenant->getTenantKey())->update(['status' => 'approved', 'response' => $response, 'handled_at' => now()]);

        $this->notices->tell($tenant, "You can subscribe to {$tenant->name} again", ['You can subscribe again.', 'Open Billing & plan and choose a plan.'.($response ? "\n\n".trim($response) : '')]);
    }

    /** The subscription is over: back to the first tier, subscribing again needs the platform's permission, and the company is told. */
    private function ended(Tenant $tenant, CompanySubscription $sub, bool $tell): void
    {
        $sub->update(['status' => 'canceled', 'cancel_at_period_end' => false, 'resubscribe_enabled' => false]);
        $this->plans->applyTier($tenant, (int) config('plans.trial_tier'));

        if ($tell) {
            $this->notices->tell($tenant, "The subscription of {$tenant->name} has ended", ['Your subscription has ended and you are on Tier 1 again.', 'Nothing was deleted. If you would like to subscribe again, ask the platform from the Billing & plan page.'.($sub->cancel_reason ? "\n\nReason noted: {$sub->cancel_reason}" : '')]);
        }
    }

    private function announceCancellation(Tenant $tenant, CompanySubscription $sub, string $by, bool $tell, string $line): void
    {
        if ($by === 'company') {
            $this->notifier->mail(PlatformNotifier::REQUESTS, "{$tenant->name} cancelled its subscription", "{$tenant->name} cancelled its subscription. {$line}".($sub->cancel_reason ? "\n\nReason: {$sub->cancel_reason}" : '')."\n\n".url('/platform/companies/'.$tenant->getTenantKey()));
        } elseif ($tell) {
            $this->notices->tell($tenant, "The subscription of {$tenant->name} was cancelled", ['The platform cancelled your subscription. '.$line, 'Until then everything works as before. You can take it back from the Billing & plan page, or get in touch.'.($sub->cancel_reason ? "\n\nReason noted: {$sub->cancel_reason}" : '')]);
        }
    }

    private function ensureAvailable(): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Online payment is not switched on.');
        }
    }

    // ---- what Stripe tells us ----------------------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $event a verified Stripe event */
    public function handle(array $event): void
    {
        $object = (array) ($event['data']['object'] ?? []);

        match ($event['type'] ?? '') {
            'checkout.session.completed' => $this->checkoutCompleted($object),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->subscriptionChanged($object, ($event['type'] ?? '') === 'customer.subscription.deleted'),
            'invoice.payment_failed' => $this->paymentFailed($object),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $session */
    private function checkoutCompleted(array $session): void
    {
        $tenant = Tenant::find($session['client_reference_id'] ?? $session['metadata']['tenant_id'] ?? null);
        if (! $tenant || ($session['mode'] ?? '') !== 'subscription') {
            return;
        }
        CompanySubscription::updateOrCreate(['tenant_id' => $tenant->getTenantKey()], array_filter([
            'stripe_customer_id' => $session['customer'] ?? null, 'stripe_subscription_id' => $session['subscription'] ?? null,
        ]));
        if (! empty($session['subscription'])) {
            $this->refresh($session['subscription']);
        }
    }

    /** @param  array<string, mixed>  $sub */
    private function subscriptionChanged(array $sub, bool $deleted): void
    {
        $tenant = $this->tenantOf($sub);
        if (! $tenant) {
            return;
        }
        $status = $deleted ? 'canceled' : (string) ($sub['status'] ?? 'incomplete');
        $tier = (int) ($sub['items']['data'][0]['price']['metadata']['tier'] ?? $sub['metadata']['tier'] ?? 0) ?: null;
        $before = CompanySubscription::where('tenant_id', $tenant->getTenantKey())->first();
        $wasGood = (bool) $before?->isGood();
        $row = CompanySubscription::updateOrCreate(['tenant_id' => $tenant->getTenantKey()], [
            'stripe_customer_id' => $sub['customer'] ?? null, 'stripe_subscription_id' => $sub['id'] ?? null, 'status' => $status, 'tier' => $tier,
            'stripe_price_id' => $sub['items']['data'][0]['price']['id'] ?? null,
            'current_period_end' => ($sub['current_period_end'] ?? $sub['items']['data'][0]['current_period_end'] ?? null) ? now()->setTimestamp((int) ($sub['current_period_end'] ?? $sub['items']['data'][0]['current_period_end'])) : null,
            'trial_end' => ! empty($sub['trial_end']) ? now()->setTimestamp((int) $sub['trial_end']) : null,
            'cancel_at_period_end' => (bool) ($sub['cancel_at_period_end'] ?? false),
        ]);

        // Stripe ended it (the period it was cancelled for has run out, or it was cancelled in Stripe)
        if ($status === 'canceled' && $wasGood) {
            $row->canceled_at ??= now();
            $this->ended($tenant, $row, true);

            return;
        }

        // paying for a tier puts the company on it (its user limit follows the tier)
        if ($row->isGood() && $tier && (int) $tenant->tier !== $tier && array_key_exists($tier, config('plans.tiers'))) {
            $this->plans->applyTier($tenant, $tier);
        }
        $this->refreshCard($row);
    }

    /** @param  array<string, mixed>  $invoice */
    private function paymentFailed(array $invoice): void
    {
        $row = CompanySubscription::where('stripe_customer_id', $invoice['customer'] ?? '')->first();
        $tenant = $row ? Tenant::find($row->tenant_id) : null;
        if (! $tenant || ! Cache::add('stripe-failed:'.($invoice['id'] ?? uniqid()), 1, now()->addDays(3))) {
            return;
        }

        $subject = "The payment for {$tenant->name} did not go through";
        $lines = ['We could not charge the card for your subscription.', 'Please update your card from the billing page so nothing is interrupted. We will try again.'];
        $admins = $tenant->run(function () use ($subject, $lines) {
            $admins = User::where('role', 'admin')->where('status', 'active')->where('is_platform_support', false)->get(['id', 'name', 'email']);
            $admins->each(fn (User $a) => DB::table('notifications_feed')->insert(['user_id' => $a->id, 'title' => $subject, 'body' => $lines[1], 'url' => '/billing', 'created_at' => now(), 'updated_at' => now()]));

            return $admins;
        });
        foreach ($admins as $admin) {
            NoticeMail::send($admin->email, $subject, ["Hi {$admin->name},", ...$lines], ['Open billing', url('/billing')]);
        }
        $this->notifier->mail(PlatformNotifier::REQUESTS, "Payment failed: {$tenant->name}", "A payment of {$tenant->name} failed. Stripe will retry it.\n\n".url('/platform/companies/'.$tenant->getTenantKey()));
    }

    /** Fetch a subscription from Stripe and apply it (used right after Checkout, whose event carries no card). */
    private function refresh(string $subscriptionId): void
    {
        try {
            $this->subscriptionChanged($this->stripe->get("subscriptions/{$subscriptionId}"), false);
        } catch (Throwable) {
            // the subscription events that follow bring the same facts
        }
    }

    /** The brand and last four digits of the card in use, for display. Failures are ignored: the card is a nicety. */
    private function refreshCard(CompanySubscription $row): void
    {
        if (! $row->stripe_subscription_id) {
            return;
        }
        try {
            $sub = $this->stripe->get("subscriptions/{$row->stripe_subscription_id}", ['expand' => ['default_payment_method']]);
            $card = $sub['default_payment_method']['card'] ?? null;
            if (! $card && $row->stripe_customer_id) {
                $card = $this->stripe->get("customers/{$row->stripe_customer_id}", ['expand' => ['invoice_settings.default_payment_method']])['invoice_settings']['default_payment_method']['card'] ?? null;
            }
            $card && $row->update(['card_brand' => $card['brand'] ?? null, 'card_last4' => $card['last4'] ?? null]);
        } catch (Throwable) {
            // not shown
        }
    }

    /** @param  array<string, mixed>  $sub */
    private function tenantOf(array $sub): ?Tenant
    {
        $id = $sub['metadata']['tenant_id'] ?? CompanySubscription::where('stripe_customer_id', $sub['customer'] ?? '')->value('tenant_id');

        return $id ? Tenant::find($id) : null;
    }
}
