<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Twilio\CallerIds;
use App\Services\Tenancy\CompanyCallerIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Company settings > Phone call: the numbers that can be a caller ID, and verifying a new one. Verifying and editing only
 * work on the company's own Twilio account; a company on the platform's account picks from what the platform offers.
 */
class CallerIdController extends Controller
{
    public function index(Request $request, CompanyCallerIds $company): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $company->overview()]);
    }

    /** Twilio calls the number; the person types the returned code. Poll status() until it is verified. */
    public function verify(Request $request, CompanyCallerIds $company, CallerIds $callerIds): JsonResponse
    {
        $account = $this->account($request, $company);
        $d = $request->validate(['number' => ['required', 'string', 'max:30'], 'label' => ['nullable', 'string', 'max:64']]);

        try {
            return response()->json(['data' => $callerIds->startVerification($account, $d['number'], (string) ($d['label'] ?? ''))]);
        } catch (Throwable $e) {
            return $this->failed($e);
        }
    }

    public function status(Request $request, CompanyCallerIds $company, CallerIds $callerIds): JsonResponse
    {
        $account = $this->account($request, $company);
        $d = $request->validate(['number' => ['required', 'string', 'max:30']]);

        try {
            return response()->json(['data' => ['verified' => $callerIds->isVerified($account, $d['number'])]]);
        } catch (Throwable $e) {
            return $this->failed($e);
        }
    }

    public function update(Request $request, string $sid, CompanyCallerIds $company, CallerIds $callerIds): JsonResponse
    {
        $account = $this->account($request, $company);
        $d = $request->validate(['label' => ['required', 'string', 'max:64']]);

        try {
            $callerIds->rename($account, $sid, $d['label']);
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function destroy(Request $request, string $sid, CompanyCallerIds $company, CallerIds $callerIds): JsonResponse
    {
        $account = $this->account($request, $company);

        try {
            $callerIds->remove($account, $sid);
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @return array{sid: string, token: string} */
    private function account(Request $request, CompanyCallerIds $company): array
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);

        return $company->managedAccount() ?? abort(403, "Verifying numbers needs the company's own Twilio account.");
    }

    private function failed(Throwable $e): JsonResponse
    {
        return response()->json(['message' => str($e->getMessage())->squish()->limit(300)->value()], 422);
    }
}
