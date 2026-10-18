<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\Subscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Before a platform administrator moves a company to another tier: what would change, what the company uses today, and, when the company pays
 * through a running Stripe subscription, what each way of billing the change would do. The Edit company form shows it in a confirmation window.
 */
class PlatformTierChangeController extends Controller
{
    public function preview(Request $request, Tenant $tenant, Plans $plans, Subscriptions $subscriptions, Seats $seats, PaymentSettings $payments): JsonResponse
    {
        $to = (int) $request->validate(['tier' => ['required', 'integer', 'between:1,'.count(config('plans.tiers'))]])['tier'];
        $from = $plans->tier((int) $tenant->tier);
        $new = $plans->tier($to);
        $running = $subscriptions->isRunning($tenant);
        $sub = $running ? $subscriptions->forTenant($tenant) : null;

        $usersAfter = Plans::isUnlimitedSeats($plans->seatsFor($to)) ? null : $plans->seatsFor($to);       // what the tier sets; the form can raise it afterwards
        $used = ['users' => $seats->usedFor($tenant), 'numbers' => $plans->numbersUsed($tenant), 'texts' => $plans->smsUsed($tenant)];
        $numbersAfter = (int) $new['sms_numbers'] + (int) $tenant->extra_numbers;
        $textsAfter = (int) $new['sms_per_month'] + $plans->extraSms($tenant);

        $warnings = [];
        $usersAfter !== null && $used['users'] > $usersAfter && $warnings[] = "It has {$used['users']} active users and the new limit is {$usersAfter}: it can keep them, but cannot add or reactivate anybody until it frees seats.";
        $used['numbers'] > $numbersAfter && $warnings[] = "It uses {$used['numbers']} texting numbers and the new tier allows {$numbersAfter}: the extra numbers (the default stays first) are dropped.";
        $used['texts'] > $textsAfter && $warnings[] = "It has already sent {$used['texts']} texts this month and the new limit is {$textsAfter}: no more texts go out until next month.";

        return response()->json(['data' => [
            'company' => $tenant->name,
            'from' => $this->card($from), 'to' => $this->card($new) + ['users_after' => $usersAfter],
            'down' => $to < (int) $tenant->tier,
            'money' => $plans->money(),
            'used' => $used,
            'warnings' => $warnings,
            'stripe' => $running ? [
                'status' => $sub->status,
                'renews' => $sub->current_period_end?->toIso8601String(),
                'cancelling' => (bool) $sub->cancel_at_period_end,
                'price_ready' => (bool) $payments->priceId($to),
                'override' => (bool) $sub->tier_override,
            ] : null,
        ]]);
    }

    /** @param  array<string, mixed>  $t */
    private function card(array $t): array
    {
        return ['tier' => $t['tier'], 'name' => $t['name'], 'users' => $t['users'], 'texts' => $t['sms_per_month'], 'numbers' => $t['sms_numbers'], 'price' => $t['price']];
    }
}
