<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Keap\KeapClient;
use App\Jobs\SyncKeapContacts;
use App\Models\Integration;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A company's Keap details (Company settings, Keap). Saving them is what lets the platform switch Keap sync on for the
 * company: until then the switch is disabled. The key is stored encrypted in the company's own integrations table and is
 * never sent back.
 */
class KeapIntegrationController extends Controller
{
    public function show(Request $request, CompanyServices $services): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $this->payload($services)]);
    }

    public function save(Request $request, CompanyServices $services): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        $d = $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:500']]);

        Integration::updateOrCreate(['provider' => 'keap'], ['credentials' => ['api_key' => trim($d['api_key'])], 'is_enabled' => true]);
        $services->setReady(tenant('id'), 'keap_sync', true);

        return response()->json(['data' => $this->payload($services)]);
    }

    /** Remove the details: Keap sync switches itself off until new ones are saved. */
    public function destroy(Request $request, CompanyServices $services): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        Integration::where('provider', 'keap')->delete();
        $services->setReady(tenant('id'), 'keap_sync', false);

        return response()->json(['data' => $this->payload($services)]);
    }

    /** Ask Keap whether the saved key is accepted, and report Keap's own answer when it is not. */
    public function test(Request $request, KeapClient $keap): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Integration::for('keap'), 422, 'Save your Keap details first.');

        try {
            $keap->ping();
        } catch (Throwable $e) {
            return response()->json(['message' => 'Keap did not accept the key: '.str($e->getMessage())->squish()->limit(200)], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function sync(Request $request, CompanyServices $services): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($services->enabled('keap_sync'), 422, 'The platform has not switched Keap sync on for your company yet.');
        SyncKeapContacts::dispatch(full: false);

        return response()->json(['queued' => true], 202);
    }

    /** @return array<string, mixed> */
    private function payload(CompanyServices $services): array
    {
        $integration = Integration::where('provider', 'keap')->first();
        $key = (string) ($integration?->credentials['api_key'] ?? '');
        $state = $services->states(tenant('id'))['keap_sync'];

        return [
            'configured' => $key !== '',
            'key_hint' => $key !== '' ? '••••'.substr($key, -4) : null,
            'ready' => $state['ready'],                    // the platform can switch it on
            'platform_on' => $state['enabled'],            // and has
            'last_synced_at' => $integration?->last_synced_at?->toIso8601String(),
            'last_run_at' => $state['last_run_at'],
        ];
    }
}
