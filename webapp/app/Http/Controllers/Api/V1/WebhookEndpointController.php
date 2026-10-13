<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Outbound webhooks (automation "HTTP POST" actions + event broadcasts). Receivers verify X-Orvyn-Signature. */
class WebhookEndpointController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::where('direction', 'out')->withCount('deliveries')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $secret = Str::random(40);
        $w = WebhookEndpoint::create($d + ['direction' => 'out', 'secret' => $secret]);

        // The signing secret is revealed exactly once.
        return response()->json(['data' => $w, 'secret' => $secret], 201);
    }

    public function update(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        abort_unless($webhook->direction === 'out', 404);
        $webhook->update($this->validated($request));

        return response()->json(['data' => $webhook]);
    }

    public function destroy(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && $webhook->direction === 'out', 403);
        $webhook->delete();

        return response()->json(null, 204);
    }

    public function deliveries(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $webhook->deliveries()->latest('id')->limit(30)->get(['id', 'event', 'response_status', 'attempts', 'delivered_at', 'created_at'])]);
    }

    private function validated(Request $request): array
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'url' => ['required', 'url:https', 'max:2000'],      // https only: payloads may contain personal data
            'events' => ['nullable', 'array'], 'events.*' => ['string', 'max:80'],
            'is_active' => ['boolean'],
        ]);

        // SSRF guard: refuse private / loopback / link-local destinations.
        $host = parse_url($d['url'], PHP_URL_HOST);
        foreach (gethostbynamel($host) ?: [] as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                abort(422, 'That address points to a private or reserved network.');
            }
        }

        return $d;
    }
}
