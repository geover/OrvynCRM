<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
use App\Models\ObjectAutomationLimit;
use App\Models\SeatRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A company asking the platform for more users, more data objects, or more automations for one data object: recording the
 * request (with the price the company was quoted), telling the platform, granting it, and telling the requester the outcome.
 * One place for all three kinds, so the API controllers and the platform's Requests page stay thin.
 */
class LimitRequests
{
    public function __construct(private readonly Pricing $pricing, private readonly PlatformNotifier $notifier, private readonly Plans $plans) {}

    /** Is a request of this kind (for this data object) already waiting for the open company? */
    public function hasPending(string $kind, ?int $dataObjectId = null): bool
    {
        return SeatRequest::pending()->where('tenant_id', tenant('id'))->where('kind', $kind)->where('data_object_id', $dataObjectId)->exists();
    }

    /** Record the request of the open company's person and e-mail the platform administrators who asked to hear about requests. */
    public function submit(User $by, string $kind, int $currentLimit, int $requestedLimit, ?string $message, ?string $dataObjectName = null, ?int $dataObjectId = null): SeatRequest
    {
        $price = $this->pricing->current();
        $unit = isset(self::PRICE_KEY[$kind]) ? $price[self::PRICE_KEY[$kind]] : null;      // texts have no quote: the platform sets them case by case

        $request = SeatRequest::create([
            'tenant_id' => tenant('id'), 'kind' => $kind, 'data_object_id' => $dataObjectId, 'data_object_name' => $dataObjectName,
            'requested_by' => AccountUser::find($by->central_user_id)?->id, 'requester_name' => $by->name, 'requester_email' => $by->email,
            'current_limit' => $currentLimit, 'requested_limit' => $requestedLimit, 'message' => $message,
            // the unit price is kept with the request so the platform reviews the figure the company saw
            'price_per_user' => $unit, 'price_currency' => $unit === null ? null : $price['currency'], 'price_period' => $unit === null ? null : $price['period'],
        ]);
        $this->tellPlatform($request);

        return $request;
    }

    /** The company clears the outcome of a handled request from its pages. A request still waiting for the platform stays visible. */
    public function dismiss(SeatRequest $request): void
    {
        abort_unless($request->tenant_id === tenant('id'), 404);
        abort_if($request->status === 'pending', 422, 'This request is still waiting for the platform.');
        $request->update(['tenant_dismissed_at' => now()]);
    }

    /** The limit the request is about, as it stands now. */
    public function limitNow(SeatRequest $request): ?int
    {
        $tenant = Tenant::find($request->tenant_id);

        return match (true) {
            ! $tenant => null,
            $request->kind === SeatRequest::DATA_OBJECTS => (int) $tenant->max_data_objects,
            $request->kind === SeatRequest::TEXTS => $this->plans->smsLimit($tenant),
            $request->kind === SeatRequest::AUTOMATIONS => (int) (ObjectAutomationLimit::where('tenant_id', $request->tenant_id)->where('data_object_id', $request->data_object_id)->value('max_automations') ?? $tenant->max_automations_per_object),
            default => (int) $tenant->max_users,
        };
    }

    /**
     * Raise the company's limit to `$limit`: its user or data object limit, or the automation limit of the one data object, or its texts
     * a month. Texts can be granted for the current month only (`$thisMonthOnly`): a bonus that ends by itself with the month.
     *
     * @return string|null the month (2026-11) a one-month text grant is for, else null
     */
    public function grant(SeatRequest $request, int $limit, bool $thisMonthOnly = false): ?string
    {
        $tenant = Tenant::findOrFail($request->tenant_id);
        if ($request->kind === SeatRequest::TEXTS && $thisMonthOnly) {
            $month = $this->plans->currentMonth($tenant);
            $tenant->update(['bonus_sms' => max(0, $limit - (int) $this->plans->tier((int) $tenant->tier)['sms_per_month'] - (int) $tenant->extra_sms), 'bonus_sms_month' => $month]);
            app(UsageTracker::class)->refresh($tenant->refresh());

            return $month;
        }
        match ($request->kind) {
            SeatRequest::DATA_OBJECTS => $tenant->update(['max_data_objects' => $limit]),
            // texts: the limit is the tier's plus the company's extra, so the extra becomes whatever is needed to reach the granted total
            SeatRequest::TEXTS => $tenant->update(['extra_sms' => max(0, $limit - (int) $this->plans->tier((int) $tenant->tier)['sms_per_month']), 'bonus_sms' => 0, 'bonus_sms_month' => null]),
            SeatRequest::AUTOMATIONS => ObjectAutomationLimit::updateOrCreate(['tenant_id' => $tenant->getTenantKey(), 'data_object_id' => $request->data_object_id], ['max_automations' => $limit]),
            default => $tenant->update(['max_users' => $limit]),
        };
        $request->kind === SeatRequest::TEXTS && app(UsageTracker::class)->refresh($tenant->refresh());      // its meters, notices and the platform's Usage page follow at once

        return null;
    }

    /** E-mail the person who asked: approved (with the new limit) or declined. Their User management / Data Objects page shows it too. */
    public function tellRequester(SeatRequest $request): void
    {
        $company = Tenant::find($request->tenant_id);
        $text = $request->status === 'approved'
            ? "Your request for {$request->subject()} for {$company?->name} was approved. The limit is now {$request->granted_limit}".($request->kind === SeatRequest::TEXTS ? ($request->granted_month ? ' texts, for '.now()->createFromFormat('Y-m', $request->granted_month)->format('F Y').' only.' : ' texts a month.') : '.')
            : "Your request for {$request->subject()} for {$company?->name} was declined.";
        $text .= $request->response ? "\n\n{$request->response}" : '';

        try {
            Mail::raw($text, fn ($m) => $m->to($request->requester_email)->subject('Your request for '.$request->subject()));
        } catch (Throwable) {
            // the company also sees the outcome in the app
        }
    }

    /** Which price setting quotes which kind of request. */
    private const PRICE_KEY = [
        SeatRequest::USERS => 'price', SeatRequest::DATA_OBJECTS => 'data_object_price', SeatRequest::AUTOMATIONS => 'automation_price',
    ];

    private function tellPlatform(SeatRequest $r): void
    {
        $company = Tenant::find($r->tenant_id);
        $extra = $r->requested_limit - $r->current_limit;
        $quote = $r->price_per_user === null ? ''
            : sprintf("Quoted: %s %s per additional %s per %s (%d more = %s %s)\n", number_format((float) $r->price_per_user, 2), $r->price_currency, $r->unit(), $r->price_period, $extra, number_format((float) $r->price_per_user * $extra, 2), $r->price_currency);

        $this->notifier->mail(
            PlatformNotifier::REQUESTS,
            "Limit request from {$company?->name}: {$r->subject()}",
            "{$r->requester_name} ({$r->requester_email}) asks for {$r->subject()} for {$company?->name}.\n\n"
            ."Current limit: {$r->current_limit}\nRequested limit: {$r->requested_limit}\n{$quote}"
            .($r->message ? "\nMessage:\n{$r->message}\n" : '')
            ."\nReview it under Platform > Requests: ".url('/platform/seat-requests'),
        );
    }
}
