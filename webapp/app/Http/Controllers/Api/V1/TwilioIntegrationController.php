<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Twilio\TwilioClient;
use App\Models\Integration;
use App\Services\Tenancy\SmsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A company's own Twilio account (Company settings, Twilio). It is optional: without it the company uses the platform's
 * account when the platform has switched that on. With it, texts and phone numbers come from the company's own account.
 * The auth token is stored encrypted in the company's own integrations table and is never sent back.
 */
class TwilioIntegrationController extends Controller
{
    public function show(Request $request, SmsSettings $platform): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $this->payload($platform)]);
    }

    public function save(Request $request, SmsSettings $platform): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        $existing = Integration::where('provider', 'twilio')->first();
        $d = $request->validate([
            'account_sid' => ['required', 'string', 'max:80', 'regex:/^AC[A-Za-z0-9]{10,}$/'],
            'auth_token' => [$existing?->credentials['auth_token'] ?? null ? 'nullable' : 'required', 'string', 'max:200'],
            'from_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-]{6,20}$/'],
            'messaging_service_sid' => ['nullable', 'string', 'max:80'],
        ], ['account_sid.regex' => 'An account SID starts with AC, as shown in the Twilio console.', 'from_number.regex' => 'Enter a phone number such as +15551234567.']);

        // an empty token keeps the saved one
        $token = trim((string) ($d['auth_token'] ?? '')) ?: ($existing?->credentials['auth_token'] ?? '');
        Integration::updateOrCreate(['provider' => 'twilio'], [
            'credentials' => ['account_sid' => trim($d['account_sid']), 'auth_token' => $token],
            'settings' => ['from_number' => $d['from_number'] ?? null, 'messaging_service_sid' => $d['messaging_service_sid'] ?? null],
            'is_enabled' => true,
        ]);

        return response()->json(['data' => $this->payload($platform)]);
    }

    /** Remove the company's own account: it goes back to the platform's, when the platform offers it. */
    public function destroy(Request $request, SmsSettings $platform): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        Integration::where('provider', 'twilio')->delete();

        return response()->json(['data' => $this->payload($platform)]);
    }

    /** Ask Twilio whether the saved account is accepted, and report Twilio's own answer when it is not. */
    public function test(Request $request, TwilioClient $twilio): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Integration::for('twilio'), 422, 'Save your Twilio details first.');

        try {
            $twilio->ping($twilio->account());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Twilio did not accept the account: '.str($e->getMessage())->squish()->limit(200)], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(SmsSettings $platform): array
    {
        $own = Integration::where('provider', 'twilio')->first();
        $c = (array) ($own?->credentials ?? []);

        return [
            'configured' => ($c['account_sid'] ?? '') !== '' && ($c['auth_token'] ?? '') !== '',
            'account_sid' => (string) ($c['account_sid'] ?? ''),
            'token_hint' => ($c['auth_token'] ?? '') !== '' ? '••••'.substr($c['auth_token'], -4) : null,
            'from_number' => (string) ($own?->settings['from_number'] ?? ''),
            'messaging_service_sid' => (string) ($own?->settings['messaging_service_sid'] ?? ''),
            'platform_available' => $platform->account() !== null,          // what the company falls back to
        ];
    }
}
