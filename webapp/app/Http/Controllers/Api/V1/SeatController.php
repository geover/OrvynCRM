<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SeatRequest;
use App\Services\Tenancy\LimitRequests;
use App\Services\Tenancy\Pricing;
use App\Services\Tenancy\Seats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A company's own view of its user limit, and asking the platform for more. Administrators only. */
class SeatController extends Controller
{
    public function show(Request $request, Seats $seats, Pricing $pricing): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $this->payload($seats, $pricing)]);
    }

    public function request(Request $request, Seats $seats, Pricing $pricing, LimitRequests $requests): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        $summary = $seats->summary();
        $d = $request->validate([
            'requested_limit' => ['required', 'integer', 'min:'.(($summary['max'] ?? 0) + 1), 'max:100000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_if($requests->hasPending(SeatRequest::USERS), 422, 'You already have a request waiting for the platform.');

        $requests->submit($request->user(), SeatRequest::USERS, (int) $summary['max'], $d['requested_limit'], $d['message'] ?? null);

        return response()->json(['data' => $this->payload($seats, $pricing)], 201);
    }

    /** @return array<string, mixed> */
    private function payload(Seats $seats, Pricing $pricing): array
    {
        $latest = SeatRequest::where('tenant_id', tenant('id'))->where('kind', SeatRequest::USERS)->latest('id')->first();

        return $seats->summary() + ['pricing' => $pricing->current()] + [
            'request' => $latest && ! ($latest->tenant_dismissed_at && $latest->status !== 'pending') ? [
                'id' => $latest->id, 'status' => $latest->status, 'requested_limit' => $latest->requested_limit, 'granted_limit' => $latest->granted_limit,
                'response' => $latest->response, 'created_at' => $latest->created_at?->toIso8601String(), 'handled_at' => $latest->handled_at?->toIso8601String(),
            ] : null,
        ];
    }
}
