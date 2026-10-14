<?php

namespace App\Http\Controllers;

use App\Models\SeatRequest;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Services\Tenancy\LimitRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Requests from companies for more users, data objects or automations (per data object): review, grant or decline. (Billing is added later.) */
class PlatformSeatRequestController extends Controller
{
    public function __construct(private readonly LimitRequests $requests) {}

    public function index(): Response
    {
        $companies = Tenant::pluck('name', 'id');

        return Inertia::render('Platform/SeatRequests', [
            'subscription_requests' => SubscriptionRequest::orderByRaw("case status when 'pending' then 0 else 1 end")->orderByDesc('id')->limit(30)->get()->map(fn (SubscriptionRequest $r) => [
                'id' => $r->id, 'company_id' => $r->tenant_id, 'company' => $companies[$r->tenant_id] ?? 'Deleted company', 'requester_name' => $r->requester_name, 'requester_email' => $r->requester_email,
                'tier' => $r->tier, 'message' => $r->message, 'status' => $r->status, 'response' => $r->response,
                'created_at' => $r->created_at?->toIso8601String(), 'handled_at' => $r->handled_at?->toIso8601String(),
            ])->values(),
            'requests' => SeatRequest::orderByRaw("case status when 'pending' then 0 else 1 end")->orderByDesc('id')->limit(200)->get()->map(fn (SeatRequest $r) => [
                'id' => $r->id, 'company_id' => $r->tenant_id, 'company' => $companies[$r->tenant_id] ?? 'Deleted company',
                'requester_name' => $r->requester_name, 'requester_email' => $r->requester_email,
                'current_limit' => $r->current_limit, 'requested_limit' => $r->requested_limit, 'granted_limit' => $r->granted_limit, 'granted_month' => $r->granted_month,
                'message' => $r->message, 'status' => $r->status, 'response' => $r->response,
                'created_at' => $r->created_at?->toIso8601String(), 'handled_at' => $r->handled_at?->toIso8601String(), 'archived_at' => $r->archived_at?->toIso8601String(),
                'kind' => $r->kind, 'data_object_id' => $r->data_object_id, 'data_object_name' => $r->data_object_name,
                'limit_now' => $this->requests->limitNow($r),
                'price_per_user' => $r->price_per_user !== null ? (float) $r->price_per_user : null, 'price_currency' => $r->price_currency, 'price_period' => $r->price_period,
            ])->values(),
        ]);
    }

    /** Grant the requested limit, or another number the platform chooses. */
    public function approve(Request $request, SeatRequest $seatRequest): RedirectResponse
    {
        abort_unless($seatRequest->status === 'pending' && ! $seatRequest->archived_at, 422, 'This request was already handled.');
        $d = $request->validate(['granted_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'], 'response' => ['nullable', 'string', 'max:1000'], 'this_month_only' => ['sometimes', 'boolean']]);
        $limit = $d['granted_limit'] ?? $seatRequest->requested_limit;

        $month = $this->requests->grant($seatRequest, $limit, $seatRequest->kind === SeatRequest::TEXTS && ! empty($d['this_month_only']));
        $this->close($seatRequest, 'approved', $limit, $d['response'] ?? null, $request, $month);

        return back()->with('success', $month ? "Approved for this month only: the limit is now {$limit} until the month ends." : "Approved: the limit is now {$limit}.");
    }

    public function decline(Request $request, SeatRequest $seatRequest): RedirectResponse
    {
        abort_unless($seatRequest->status === 'pending', 422, 'This request was already handled.');
        $d = $request->validate(['response' => ['nullable', 'string', 'max:1000']]);
        $this->close($seatRequest, 'declined', null, $d['response'] ?? null, $request);

        return back()->with('success', 'Declined.');
    }

    /** Tidy away a handled request (a waiting one has to be approved or declined first). */
    public function archive(SeatRequest $seatRequest): RedirectResponse
    {
        abort_if($seatRequest->status === 'pending', 422, 'Approve or decline the request before archiving it.');
        $seatRequest->update(['archived_at' => now()]);

        return back()->with('success', 'Archived.');
    }

    public function unarchive(SeatRequest $seatRequest): RedirectResponse
    {
        $seatRequest->update(['archived_at' => null]);

        return back()->with('success', 'Restored.');
    }

    /** Permanent; only archived requests. */
    public function destroy(SeatRequest $seatRequest): RedirectResponse
    {
        abort_unless($seatRequest->archived_at, 422, 'Archive it first; only archived requests can be deleted permanently.');
        $seatRequest->delete();

        return back()->with('success', 'Deleted.');
    }

    /** Record the outcome and tell the person who asked. */
    private function close(SeatRequest $r, string $status, ?int $granted, ?string $response, Request $request, ?string $month = null): void
    {
        $r->update(['status' => $status, 'granted_limit' => $granted, 'granted_month' => $month, 'response' => $response, 'handled_by' => $request->attributes->get('platform_identity')?->id, 'handled_at' => now()]);
        $this->requests->tellRequester($r);
    }
}
