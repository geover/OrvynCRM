<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Services\Tenancy\CompanyAdminNotices;
use App\Services\Tenancy\Subscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What a platform administrator does with a company's subscription: cancel it (at the end of the period or at once), take a
 * cancellation back, allow the company to subscribe again, and answer its request to do so.
 */
class PlatformSubscriptionController extends Controller
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function cancel(Request $request, Tenant $tenant): RedirectResponse
    {
        $d = $request->validate(['immediately' => ['sometimes', 'boolean'], 'reason' => ['nullable', 'string', 'max:500'], 'tell' => ['sometimes', 'boolean']]);

        try {
            $this->subscriptions->cancel($tenant, (bool) ($d['immediately'] ?? false), $d['reason'] ?? null, 'platform', (bool) ($d['tell'] ?? true));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subscription' => $e->getMessage()]);
        }

        return back()->with('success', ($d['immediately'] ?? false) ? "{$tenant->name}'s subscription was cancelled and has ended." : "{$tenant->name}'s subscription will end when the period already paid for is over.");
    }

    public function resume(Tenant $tenant): RedirectResponse
    {
        try {
            $this->subscriptions->resume($tenant, 'platform');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subscription' => $e->getMessage()]);
        }

        return back()->with('success', 'The cancellation was withdrawn: the subscription carries on.');
    }

    /** Let the company subscribe again, without waiting for a request. */
    public function enable(Request $request, Tenant $tenant): RedirectResponse
    {
        $d = $request->validate(['response' => ['nullable', 'string', 'max:1000']]);
        $this->subscriptions->enableResubscribe($tenant, $d['response'] ?? null);

        return back()->with('success', "{$tenant->name} can subscribe again. Its administrators were told.");
    }

    public function approve(Request $request, SubscriptionRequest $subscriptionRequest): RedirectResponse
    {
        abort_unless($subscriptionRequest->status === 'pending', 422, 'This request was already handled.');
        $d = $request->validate(['response' => ['nullable', 'string', 'max:1000']]);
        $tenant = Tenant::findOrFail($subscriptionRequest->tenant_id);
        $this->subscriptions->enableResubscribe($tenant, $d['response'] ?? null);
        $subscriptionRequest->update(['handled_by' => $request->attributes->get('platform_identity')?->id]);

        return back()->with('success', "Enabled: {$tenant->name} can subscribe again and was told.");
    }

    public function decline(Request $request, SubscriptionRequest $subscriptionRequest, CompanyAdminNotices $notices): RedirectResponse
    {
        abort_unless($subscriptionRequest->status === 'pending', 422, 'This request was already handled.');
        $d = $request->validate(['response' => ['nullable', 'string', 'max:1000']]);
        $subscriptionRequest->update(['status' => 'declined', 'response' => $d['response'] ?? null, 'handled_at' => now(), 'handled_by' => $request->attributes->get('platform_identity')?->id]);

        if ($tenant = Tenant::find($subscriptionRequest->tenant_id)) {
            $notices->tell($tenant, 'Your request to subscribe again', [
                'The platform cannot enable your subscription at the moment.',
                'You can get in touch from Messages in the sidebar.'.($subscriptionRequest->response ? "\n\n".$subscriptionRequest->response : ''),
            ]);
        }

        return back()->with('success', 'Declined. The company was told.');
    }
}
