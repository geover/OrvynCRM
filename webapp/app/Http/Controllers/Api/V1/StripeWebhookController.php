<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Stripe\StripeClient;
use App\Services\Tenancy\Subscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe's callbacks (subscription created, changed or cancelled; payment failed). One address for the whole platform: Stripe signs
 * every body with the endpoint's signing secret and a body that is not signed by it is refused. Each event is applied once.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeClient $stripe, Subscriptions $subscriptions): JsonResponse
    {
        $payload = $request->getContent();
        abort_unless($stripe->validSignature($payload, $request->header('Stripe-Signature')), 400, 'Bad signature.');

        $event = json_decode($payload, true);
        abort_unless(is_array($event) && isset($event['id'], $event['type']), 400, 'Bad event.');

        // Stripe may deliver an event twice: the second time does nothing
        $key = 'stripe-event:'.$event['id'];
        if (! Cache::add($key, 1, now()->addDays(3))) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $subscriptions->handle($event);
        } catch (Throwable $e) {
            Cache::forget($key);                            // let Stripe try again
            Log::error('Stripe event failed', ['id' => $event['id'], 'type' => $event['type'], 'error' => $e->getMessage()]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
