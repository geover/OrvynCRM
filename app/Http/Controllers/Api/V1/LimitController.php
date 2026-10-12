<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataObject;
use App\Models\SeatRequest;
use App\Services\Tenancy\LimitRequests;
use App\Services\Tenancy\Limits;
use App\Services\Tenancy\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A company's own view of how many data objects it may have and how many automations each data object may have, and
 * asking the platform for more of either. Administrators only. (Requests live in the same table as requests for users.)
 */
class LimitController extends Controller
{
    public function show(Request $request, Limits $limits, Pricing $pricing): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $this->payload($limits, $pricing)]);
    }

    public function request(Request $request, Limits $limits, Pricing $pricing, LimitRequests $requests): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        $d = $request->validate([
            'kind' => ['required', Rule::in([SeatRequest::DATA_OBJECTS, SeatRequest::AUTOMATIONS])],
            'data_object_id' => ['required_if:kind,'.SeatRequest::AUTOMATIONS, 'nullable', 'exists:data_objects,id'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $objectId = $d['kind'] === SeatRequest::AUTOMATIONS ? (int) $d['data_object_id'] : null;
        $current = (int) ($objectId ? $limits->automationsMax($objectId) : $limits->dataObjectsMax());
        $asked = $request->validate(['requested_limit' => ['required', 'integer', 'min:'.($current + 1), 'max:100000']])['requested_limit'];
        abort_if($requests->hasPending($d['kind'], $objectId), 422, 'You already have a request waiting for the platform.');

        $requests->submit($request->user(), $d['kind'], $current, $asked, $d['message'] ?? null, $objectId ? DataObject::whereKey($objectId)->value('name') : null, $objectId);

        return response()->json(['data' => $this->payload($limits, $pricing)], 201);
    }

    /** Clear the outcome (approved or declined) of a handled request from the company's pages. */
    public function dismiss(Request $request, SeatRequest $seatRequest, LimitRequests $requests): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        $requests->dismiss($seatRequest);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(Limits $limits, Pricing $pricing): array
    {
        $requests = SeatRequest::where('tenant_id', tenant('id'))->whereIn('kind', [SeatRequest::DATA_OBJECTS, SeatRequest::AUTOMATIONS])->orderByDesc('id')->limit(60)->get()
            ->unique(fn (SeatRequest $r) => $r->kind.':'.$r->data_object_id)                  // the latest per target
            ->reject(fn (SeatRequest $r) => $r->tenant_dismissed_at && $r->status !== 'pending')   // cleared by the company
            ->map(fn (SeatRequest $r) => [
                'id' => $r->id, 'kind' => $r->kind, 'data_object_id' => $r->data_object_id, 'status' => $r->status, 'requested_limit' => $r->requested_limit,
                'granted_limit' => $r->granted_limit, 'response' => $r->response, 'created_at' => $r->created_at?->toIso8601String(),
            ])->values();

        return [
            'data_objects' => $limits->dataObjects(), 'automations_default' => $limits->automationsDefault(),
            'objects' => $limits->objects(), 'pricing' => $pricing->current(), 'requests' => $requests,
        ];
    }
}
