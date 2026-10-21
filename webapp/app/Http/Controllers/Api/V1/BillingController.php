<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanyBilling;
use App\Models\CompanyUsage;
use App\Models\SeatRequest;
use App\Models\Tenant;
use App\Services\Tenancy\LimitRequests;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\PlatformNotifier;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\SmsNumbers;
use App\Services\Tenancy\Subscriptions;
use App\Services\Tenancy\UsageTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Plan and billing of the open company. Everybody may read the plan and where the trial stands; only administrators see and
 * change the billing details and the plan. Charging is not part of the application: the platform invoices from these details.
 */
class BillingController extends Controller
{
    public function __construct(private readonly Plans $plans, private readonly PlatformNotifier $notifier, private readonly Subscriptions $subscriptions) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request, $this->company())]);
    }

    public function save(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $company = $this->company();
        $d = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:30'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'po_reference' => ['nullable', 'string', 'max:80'],
        ]);
        $d['country'] = strtoupper($d['country']);

        $wasComplete = $this->plans->billingComplete($company);
        $billing = CompanyBilling::updateOrCreate(['tenant_id' => $company->getTenantKey()], $d);
        $billing->update(['completed_at' => $billing->isComplete() ? ($billing->completed_at ?? now()) : null]);

        if (! $wasComplete) {
            $this->notifier->mail(PlatformNotifier::REQUESTS, "{$company->name} entered its billing information", "{$request->user()->name} ({$request->user()->email}) entered the billing information of {$company->name}.\n\nSee it under Platform > Companies, then the company's page: ".url('/platform/companies/'.$company->getTenantKey()));
        }

        return response()->json(['data' => $this->payload($request, $company->fresh())]);
    }

    /** Choose another tier. Paid tiers need the billing details; a smaller tier must still hold what the company already has. */
    public function changePlan(Request $request, Seats $seats, SmsNumbers $numbers): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $company = $this->company();
        $d = $request->validate(['tier' => ['required', 'integer', 'between:1,'.count(config('plans.tiers'))]]);
        $tier = (int) $d['tier'];
        $target = $this->plans->tier($tier);

        if ($tier === (int) $company->tier) {
            throw ValidationException::withMessages(['tier' => 'That is your current plan.']);
        }
        $paying = (bool) $this->subscriptions->forTenant($company)?->isGood();
        if ($this->subscriptions->available() && ! $paying) {
            throw ValidationException::withMessages(['tier' => 'Subscribe to a plan with the Subscribe button: you pay on Stripe\'s secure page.']);
        }
        if (! $this->subscriptions->available() && ! $this->plans->billingComplete($company)) {
            throw ValidationException::withMessages(['tier' => 'Enter your billing information first, then choose a plan.']);
        }
        $users = $seats->used();
        if ($target['users'] !== null && $users > $target['users']) {
            throw ValidationException::withMessages(['tier' => "{$target['name']} allows {$target['users']} active users and you have {$users}. Suspend or remove some people first."]);
        }
        $chosen = count($numbers->chosen());
        $allowedNumbers = $target['sms_numbers'] + (int) $company->extra_numbers;
        if ($chosen > $allowedNumbers) {
            throw ValidationException::withMessages(['tier' => "{$target['name']} allows {$allowedNumbers} texting ".($allowedNumbers === 1 ? 'number' : 'numbers')." and you use {$chosen}. Remove some under Company settings, Twilio."]);
        }

        $from = $this->plans->tier((int) $company->tier)['name'];
        try {
            $this->subscriptions->changeTier($company, $tier);                // a running subscription is moved at Stripe first
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['tier' => $e->getMessage()]);
        }
        $this->plans->applyTier($company, $tier);
        $this->notifier->mail(PlatformNotifier::REQUESTS, "{$company->name} moved to {$target['name']}", "{$request->user()->name} ({$request->user()->email}) moved {$company->name} from {$from} to {$target['name']}.\n\n".url('/platform/companies/'.$company->getTenantKey()));

        return response()->json(['data' => $this->payload($request, $company->fresh())]);
    }

    /** The Stripe Checkout page for subscribing to a tier. The browser is sent there; the card is typed on Stripe's page. */
    public function checkout(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['tier' => ['required', 'integer', 'between:1,'.count(config('plans.tiers'))]]);

        try {
            return response()->json(['url' => $this->subscriptions->checkoutUrl($this->company(), (int) $d['tier'], $request->user())]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['tier' => $e->getMessage()]);
        }
    }

    /** Cancel at the end of the period already paid for. The company keeps its plan until then and can change its mind. */
    public function cancel(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->subscriptions->cancel($this->company(), false, $d['reason'] ?? null, 'company');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subscription' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->payload($request, $this->company())]);
    }

    /** Withdraw a cancellation that has not taken effect yet. */
    public function resume(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        try {
            $this->subscriptions->resume($this->company(), 'company');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subscription' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->payload($request, $this->company())]);
    }

    /** After a cancellation: ask the platform to enable subscribing again. */
    public function resubscribe(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['tier' => ['nullable', 'integer', 'between:1,'.count(config('plans.tiers'))], 'message' => ['nullable', 'string', 'max:1000']]);
        $this->subscriptions->requestResubscribe($request->user(), $this->company(), $d['tier'] ?? null, $d['message'] ?? null);

        return response()->json(['data' => $this->payload($request, $this->company())]);
    }

    /** Ask the platform for more texts per month (the same request list as more users, data objects and automations). */
    public function requestTexts(Request $request, LimitRequests $requests): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        $company = $this->company();
        $current = $this->plans->smsLimit($company);
        $d = $request->validate([
            'requested_limit' => ['required', 'integer', 'min:'.($current + 1), 'max:10000000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_if($requests->hasPending(SeatRequest::TEXTS), 422, 'You already have a request for more texts waiting for the platform.');

        $requests->submit($request->user(), SeatRequest::TEXTS, $current, (int) $d['requested_limit'], $d['message'] ?? null);

        return response()->json(['data' => $this->payload($request, $company)], 201);
    }

    /** Stripe's Customer Portal: card, invoices, cancelling. */
    public function portal(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        try {
            return response()->json(['url' => $this->subscriptions->portalUrl($this->company())]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['tier' => $e->getMessage()]);
        }
    }

    private function company(): Tenant
    {
        return Tenant::findOrFail(tenant('id'));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, Tenant $company): array
    {
        $numbers = app(SmsNumbers::class);
        $out = $this->plans->summary($company) + [
            'is_admin' => $request->user()->isAdmin(),
            'sms_numbers' => ['used' => max(count($numbers->chosen()), count($numbers->usable())), 'max' => $this->plans->smsNumbersMax($company)],
            'trial_months' => $this->plans->trialMonths(),
            'subscription' => $this->subscriptions->summary($company),
        ];

        if ($out['is_admin']) {
            $b = $this->plans->billing($company);
            $out['tiers'] = $this->plans->tiers();
            // the latest request for more texts, until the company clears an answered one
            $asked = SeatRequest::where('tenant_id', $company->getTenantKey())->where('kind', SeatRequest::TEXTS)->latest('id')->first();
            $out['text_request'] = $asked && ! ($asked->tenant_dismissed_at && $asked->status !== 'pending')
                ? $asked->only(['id', 'status', 'requested_limit', 'granted_limit', 'granted_month', 'response']) + ['created_at' => $asked->created_at?->toIso8601String()] : null;
            $out['history'] = app(UsageTracker::class)->history($company)->map(fn (CompanyUsage $u) => $u->only(['period', 'tier', 'users', 'users_limit', 'sms', 'sms_limit', 'numbers', 'numbers_limit']))->values()->all();
            $out['billing'] = collect(CompanyBilling::REQUIRED)->merge(['phone', 'address_line2', 'region', 'tax_id', 'po_reference'])->mapWithKeys(fn ($f) => [$f => (string) ($b?->{$f} ?? '')])->all();
        }

        return $out;
    }
}
