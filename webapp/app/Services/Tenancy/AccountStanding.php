<?php

namespace App\Services\Tenancy;

use App\Models\CompanyBilling;
use App\Models\CompanyInvoice;
use App\Models\CompanyManualInvoice;
use App\Models\CompanyPaymentReminder;
use App\Models\CompanySubscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where each company stands with paying, in words the platform can act on, and the reminders about it. One place for the Account standing
 * page, the company overview, the menu badge and the daily follow-up of failed payments.
 */
class AccountStanding
{
    /** code => [label, tone]. The tone only picks the badge colour. */
    public const STANDINGS = [
        'failed' => ['Payment failed', 'red'],
        'overdue' => ['Invoice overdue', 'red'],
        'trial_ended' => ['Trial ended, not paying', 'red'],
        'no_billing' => ['No billing details', 'amber'],
        'trial_ending' => ['Trial ending soon', 'amber'],
        'cancelled' => ['Cancelled', 'slate'],
        'trial' => ['On trial', 'sky'],
        'invoiced' => ['Invoiced', 'violet'],
        'paid' => ['Paid, up to date', 'emerald'],
        'inactive' => ['Suspended or closed', 'slate'],
    ];

    /** Days before the end of a trial from which it counts as "ending soon". */
    private const TRIAL_SOON = 14;

    public function __construct(private readonly Plans $plans, private readonly PlatformNotifier $notifier) {}

    /**
     * Every company with its standing.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $subs = CompanySubscription::get()->keyBy('tenant_id');
        $billed = CompanyBilling::whereNotNull('completed_at')->pluck('tenant_id')->flip();
        $latest = CompanyInvoice::whereIn('id', CompanyInvoice::selectRaw('max(id)')->groupBy('tenant_id'))->get()->keyBy('tenant_id');
        $reminded = CompanyPaymentReminder::whereIn('id', CompanyPaymentReminder::selectRaw('max(id)')->groupBy('tenant_id'))->get()->keyBy('tenant_id');
        $unpaid = CompanyManualInvoice::unpaid()->orderBy('due_on')->get()->groupBy('tenant_id');
        $money = $this->plans->money();

        return Tenant::orderBy('name')->get()->map(fn (Tenant $t) => $this->row($t, $subs[$t->getTenantKey()] ?? null, $billed->has($t->getTenantKey()), $latest[$t->getTenantKey()] ?? null, $reminded[$t->getTenantKey()] ?? null, $money, $unpaid[$t->getTenantKey()] ?? collect()))->all();
    }

    /** @return array<string, mixed> */
    public function of(Tenant $tenant): array
    {
        $id = $tenant->getTenantKey();

        return $this->row(
            $tenant, CompanySubscription::where('tenant_id', $id)->first(), (bool) CompanyBilling::where('tenant_id', $id)->whereNotNull('completed_at')->exists(),
            CompanyInvoice::where('tenant_id', $id)->orderByDesc('id')->first(), CompanyPaymentReminder::where('tenant_id', $id)->orderByDesc('id')->first(), $this->plans->money(),
            CompanyManualInvoice::unpaid()->where('tenant_id', $id)->orderBy('due_on')->get(),
        );
    }

    /**
     * How many companies are in each standing, and what the paying ones come to per billing period.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function totals(array $rows): array
    {
        $counts = array_fill_keys(array_keys(self::STANDINGS), 0);
        $expected = 0.0;
        $atRisk = 0.0;
        $outstanding = 0.0;
        $currency = strtolower($this->plans->money()['currency']);
        foreach ($rows as $r) {
            $counts[$r['standing']]++;
            $r['standing'] === 'paid' && $expected += (float) $r['price'];
            $r['standing'] === 'failed' && $atRisk += (float) $r['price'];
            $r['unpaid'] && strtolower($r['unpaid']['currency']) === $currency && $outstanding += $r['unpaid']['amount'] / $this->factor($currency);
        }

        return ['counts' => $counts, 'expected' => round($expected, 2), 'at_risk' => round($atRisk, 2), 'outstanding' => round($outstanding, 2), 'money' => $this->plans->money()];
    }

    /**
     * @param  array{currency: string, period: string}  $money
     * @return array<string, mixed>
     */
    private function row(Tenant $t, ?CompanySubscription $sub, bool $billed, ?CompanyInvoice $invoice, ?CompanyPaymentReminder $reminder, array $money, Collection $unpaid): array
    {
        $overdue = $unpaid->filter(fn (CompanyManualInvoice $i) => $i->isOverdue())->sortBy('due_on')->values();
        $paying = (bool) $sub?->isGood();
        $state = $this->plans->state($t, $billed, $paying);
        $failing = $invoice?->status === 'failed' ? $invoice : null;
        $pastDue = in_array($sub?->status, ['past_due', 'unpaid'], true);

        [$code, $since] = match (true) {
            ! $t->isActive() => ['inactive', null],
            $pastDue || $failing !== null => ['failed', $failing?->failed_at ?? $sub?->updated_at],
            $overdue->isNotEmpty() => ['overdue', $overdue->first()->due_on],
            $sub !== null && ($sub->status === 'canceled' || $sub->cancel_at_period_end) => ['cancelled', $sub->canceled_at ?? $sub->current_period_end],
            $paying => ['paid', $sub->current_period_end],
            $state['state'] === 'expired' => ['trial_ended', $t->trial_ends_at],
            $state['state'] === 'trial' => [($state['days_left'] ?? 99) <= self::TRIAL_SOON ? 'trial_ending' : 'trial', $t->trial_ends_at],
            $billed => ['invoiced', null],
            default => ['no_billing', null],
        };

        return [
            'id' => $t->getTenantKey(), 'name' => $t->name, 'slug' => $t->slug, 'tier' => (int) $t->tier, 'tier_name' => $this->plans->tier((int) $t->tier)['name'],
            'standing' => $code, 'label' => self::STANDINGS[$code][0], 'tone' => self::STANDINGS[$code][1], 'since' => $since instanceof Carbon ? $since->toIso8601String() : null,
            'price' => $this->plans->price((int) $t->tier), 'money' => $money,
            'renews_at' => $sub?->current_period_end?->toIso8601String(), 'cancel_at_period_end' => (bool) $sub?->cancel_at_period_end,
            'card' => $sub?->card_last4 ? ['brand' => $sub->card_brand, 'last4' => $sub->card_last4] : null,
            'trial_ends_at' => $t->trial_ends_at?->toIso8601String(), 'days_left' => $state['days_left'],
            'failing' => $failing ? [
                'amount' => $failing->amount_due, 'currency' => $failing->currency, 'attempts' => $failing->attempt_count,
                'failed_at' => $failing->failed_at?->toIso8601String(), 'next_attempt_at' => $failing->next_attempt_at?->toIso8601String(), 'url' => $failing->hosted_url,
            ] : null,
            'last_invoice' => $invoice ? ['status' => $invoice->status, 'amount' => $invoice->amount_due, 'currency' => $invoice->currency, 'at' => $invoice->issued_at?->toIso8601String(), 'number' => $invoice->number] : null,
            'last_reminder' => $reminder ? ['at' => $reminder->created_at?->toIso8601String(), 'kind' => $reminder->kind] : null,
            // the invoices the platform issued itself (companies it bills outside Stripe): what is unpaid, and which of it is past due
            'unpaid' => $unpaid->isEmpty() ? null : [
                'count' => $unpaid->count(), 'overdue' => $overdue->count(), 'amount' => (int) $unpaid->sum('amount_due'), 'currency' => $unpaid->first()->currency,
                'oldest_due' => $unpaid->first()->due_on->toDateString(), 'oldest_number' => $unpaid->first()->number, 'oldest_amount' => (int) $unpaid->first()->amount_due,
                'overdue_days' => $overdue->isEmpty() ? 0 : (int) $overdue->first()->due_on->diffInDays(now()->startOfDay()),
            ],
            'can_remind' => in_array($code, ['failed', 'overdue', 'trial_ending', 'trial_ended', 'no_billing'], true),
        ];
    }

    /**
     * Tell a company's administrators about the money matter it stands in (a notice and an e-mail), once a day at most when sent by hand.
     *
     * @return array{ok: bool, message: string}
     */
    public function remind(Tenant $tenant, string $kind = 'manual', ?int $by = null): array
    {
        $row = $this->of($tenant);
        if (! $row['can_remind']) {
            return ['ok' => false, 'message' => "Nothing to remind {$tenant->name} about: its standing is “{$row['label']}”."];
        }
        if ($kind === 'manual' && CompanyPaymentReminder::where('tenant_id', $tenant->getTenantKey())->where('kind', 'manual')->where('created_at', '>=', now()->subDay())->exists()) {
            return ['ok' => false, 'message' => "{$tenant->name} was already reminded in the last 24 hours."];
        }

        [$subject, $lines] = $this->words($row, $kind);
        app(CompanyAdminNotices::class)->tell($tenant, $subject, $lines);
        CompanyPaymentReminder::create(['tenant_id' => $tenant->getTenantKey(), 'kind' => $kind, 'sent_by' => $by, 'standing' => $row['standing'], 'subject' => $subject, 'created_at' => now()]);

        return ['ok' => true, 'message' => "The administrators of {$tenant->name} were told."];
    }

    /**
     * The daily follow-up of failed payments and overdue invoices: a reminder after 3 days and a firmer one after 7, and the platform is told once
     * when it is still unpaid after 7. Each step is sent once per failure or per overdue invoice.
     *
     * @return int how many reminders went out
     */
    public function followUps(): int
    {
        $sent = 0;
        foreach ($this->all() as $row) {
            if (! in_array($row['standing'], ['failed', 'overdue'], true)) {
                continue;
            }
            $from = $row['standing'] === 'failed' ? ($row['failing']['failed_at'] ?? $row['since'] ?? now()) : $row['since'];
            $since = Carbon::parse($from);
            $days = (int) $since->diffInDays(now());
            $tenant = Tenant::find($row['id']);
            foreach ([7 => $row['standing'].'_7', 3 => $row['standing'].'_3'] as $after => $kind) {
                if ($days < $after) {
                    continue;
                }
                if (CompanyPaymentReminder::where('tenant_id', $row['id'])->where('kind', $kind)->where('created_at', '>=', $since)->exists()) {
                    break;                                  // this step was done; a later (higher) one was checked first
                }
                if ($tenant && $this->remind($tenant, $kind)['ok']) {
                    $sent++;
                    $after === 7 && $this->notifier->mail(PlatformNotifier::REQUESTS, "Still unpaid after 7 days: {$tenant->name}", "{$tenant->name} has not paid for 7 days ({$row['label']}). It was reminded twice.\n\nSee its account standing: ".url('/platform/standing'));
                }
                break;
            }
        }

        return $sent;
    }

    /** How many of the smallest unit make one of the currency: 100 for most, 1 for the zero-decimal ones. */
    public function factor(string $currency): int
    {
        return in_array(strtoupper($currency), ['JPY', 'KRW', 'VND', 'CLP'], true) ? 1 : 100;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: list<string>}
     */
    private function words(array $row, string $kind): array
    {
        $date = fn (?string $iso) => $iso ? Carbon::parse($iso)->format('F j, Y') : null;

        return match ($row['standing']) {
            'failed' => [
                $kind === 'failed_7' ? 'Your payment is still outstanding' : 'Your payment did not go through',
                array_values(array_filter([
                    'We could not charge your card for your subscription'.($row['failing'] ? ' ('.number_format($row['failing']['amount'] / 100, 2).' '.strtoupper($row['failing']['currency']).', '.$row['failing']['attempts'].' '.($row['failing']['attempts'] === 1 ? 'attempt' : 'attempts').')' : '').'.',
                    $kind === 'failed_7' ? 'It has been unpaid for a week. Please update your card on the billing page now, so that nothing is interrupted.' : 'Please update your card on the billing page so that nothing is interrupted.',
                    ($row['failing']['next_attempt_at'] ?? null) ? 'We will try again on '.$date($row['failing']['next_attempt_at']).'.' : null,
                ])),
            ],
            'overdue' => [
                $kind === 'overdue_7' ? 'Your invoice is still unpaid' : 'An invoice is overdue',
                array_values(array_filter([
                    'Invoice '.($row['unpaid']['oldest_number'] ?? '').' ('.number_format($row['unpaid']['oldest_amount'] / $this->factor($row['unpaid']['currency']), 2).' '.strtoupper($row['unpaid']['currency']).') was due on '.$date($row['unpaid']['oldest_due']).' and has not been paid.',
                    $row['unpaid']['overdue'] > 1 ? $row['unpaid']['overdue'].' invoices are overdue.' : null,
                    $kind === 'overdue_7' ? 'It has been more than a week. Please pay it now, or write to us if something is in the way.' : 'Please pay it, or write to us if something is in the way.',
                ])),
            ],
            'trial_ending' => ['Your free trial ends '.($date($row['trial_ends_at']) ?? 'soon'), ['Your free trial ends '.($date($row['trial_ends_at']) ?? 'soon').'.', 'To carry on without interruption, enter your billing details or subscribe on the billing page. Nothing is deleted if you do not.']],
            'trial_ended' => ['Your free trial has ended', ['Your free trial has ended and no subscription or billing details are on file.', 'Only the billing page stays open until you subscribe or enter your billing details. Nothing has been deleted.']],
            default => ['Please add your billing details', ['We do not have your billing details yet.', 'Please enter them on the billing page so that we can invoice you.']],
        };
    }
}
