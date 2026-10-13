<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\CompanyCallerIds;
use App\Services\Tenancy\SmsNumbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The numbers a company sends texts from, as many as its licence tier allows (Admin settings, Twilio). */
class SmsNumbersController extends Controller
{
    public function show(Request $request, SmsNumbers $numbers, CompanyCallerIds $callerIds): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $this->payload($numbers, $callerIds)]);
    }

    /** The numbers anybody may pick for one text (the text composer's drop-down), default first. */
    public function options(SmsNumbers $numbers): JsonResponse
    {
        return response()->json(['data' => ['numbers' => $numbers->usable()]]);
    }

    public function save(Request $request, SmsNumbers $numbers, CompanyCallerIds $callerIds): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        $d = $request->validate(['numbers' => ['present', 'array', 'max:50'], 'numbers.*' => ['string', 'max:30']]);
        $numbers->save($d['numbers']);

        return response()->json(['data' => $this->payload($numbers, $callerIds)]);
    }

    /** @return array<string, mixed> */
    private function payload(SmsNumbers $numbers, CompanyCallerIds $callerIds): array
    {
        $overview = $callerIds->overview();

        return [
            'chosen' => $numbers->chosen(), 'max' => $numbers->max(), 'usable' => $numbers->usable(),
            'suggestions' => array_map(fn ($n) => ['number' => $n['number'], 'label' => $n['label']], $overview['numbers']),
            'source' => $overview['source'], 'error' => $overview['error'],
        ];
    }
}
