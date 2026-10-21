<?php

namespace App\Services\Onboarding;

use App\Models\CompanyOnboarding;
use App\Models\CompanySubscription;
use App\Models\OnboardingEmail;
use App\Models\OnboardingSend;
use App\Models\Tenant;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\PlatformNotifier;
use Illuminate\Support\Str;

/**
 * The onboarding series. For every company that is in it, a run reads which steps the company has really taken, decides whether an e-mail is due (the first one at once,
 * then the one for the first step not yet taken, no sooner than the gap and never more than the weekly limit), and works out whether the company needs a hand.
 *
 * Nothing here ever touches a company's own data except to count it.
 */
class Onboarding
{
    public function __construct(
        private readonly Milestones $milestones,
        private readonly OnboardingSettings $settings,
        private readonly OnboardingMailer $mailer,
        private readonly Plans $plans,
        private readonly PlatformNotifier $notifier,
    ) {}

    /** Puts a company into the series (once; a second call changes nothing). */
    public function start(Tenant $tenant): CompanyOnboarding
    {
        DefaultEmails::ensure();

        return CompanyOnboarding::firstOrCreate(['tenant_id' => $tenant->getTenantKey()], [
            'state' => 'active', 'started_at' => now(), 'unsubscribe_token' => Str::random(40), 'milestones' => ['account' => now()->toIso8601String()],
        ]);
    }

    /** A run over every company in the series. @return array{checked: int, sent: int, flagged: int} */
    public function run(): array
    {
        $checked = $sent = 0;
        CompanyOnboarding::with('tenant')->whereIn('state', ['active', 'paused', 'unsubscribed'])->orderBy('id')->chunkById(100, function ($list) use (&$checked, &$sent) {
            foreach ($list as $o) {
                if (! $o->tenant || ! $o->tenant->isActive()) {
                    continue;
                }
                $checked++;
                $sent += $this->evaluate($o) ? 1 : 0;
            }
        });

        return ['checked' => $checked, 'sent' => $sent, 'flagged' => CompanyOnboarding::where('needs_help', true)->count()];
    }

    /** One company: what it has done, whether an e-mail is due, whether it needs help. @return bool whether an e-mail was sent */
    public function evaluate(CompanyOnboarding $o): bool
    {
        $reached = $this->refresh($o);
        $sent = false;

        if ($o->state === 'active' && $this->settings->on('enabled')) {
            $sent = $this->due($o, $reached) && $this->sendNext($o, $reached) !== null;
            $this->completeIfDone($o, $reached);
        }
        $this->detectHelp($o, $reached);

        return $sent;
    }

    /** Reads the steps from the company, remembers new ones. @return array<string, bool> */
    public function refresh(CompanyOnboarding $o): array
    {
        $reached = $this->milestones->reached($o->tenant);
        $before = $o->milestones ?? [];
        $now = $before;
        $new = false;
        foreach ($reached as $key => $done) {
            if ($done && ! isset($now[$key])) {
                $now[$key] = now()->toIso8601String();
                $key !== 'account' && $new = true;
            }
        }
        if ($now !== $before) {
            $o->forceFill(['milestones' => $now] + ($new ? ['last_progress_at' => now()] : []))->save();
        }

        return $reached;
    }

    /** Whether the gap and the weekly limit allow an e-mail now (the very first one is always due). */
    private function due(CompanyOnboarding $o, array $reached): bool
    {
        if ($o->emails_sent === 0 || ! $o->last_sent_at) {
            return true;
        }
        $week = OnboardingSend::where('tenant_id', $o->tenant_id)->where('status', 'sent')->where('sent_at', '>=', now()->subDays(7))->count();

        return $o->last_sent_at->lte(now()->subDays($this->settings->int('gap_days'))) && $week < $this->settings->int('max_per_week');
    }

    /** The e-mail that should go next: the welcome, then the one for the first step not taken, then the "all set". Null when there is nothing left to send. */
    public function nextEmail(CompanyOnboarding $o, array $reached): ?OnboardingEmail
    {
        $sent = OnboardingSend::where('tenant_id', $o->tenant_id)->where('status', 'sent')->pluck('email_key')->all();
        $emails = OnboardingEmail::where('enabled', true)->orderBy('position')->get()->keyBy('key');

        if (! in_array('welcome', $sent, true) && isset($emails['welcome'])) {
            return $emails['welcome'];
        }
        $open = array_values(array_filter(Milestones::steps(), fn ($m) => ! ($reached[$m['key']] ?? false)));
        if (! $open) {
            return ! in_array('all_set', $sent, true) ? ($emails['all_set'] ?? null) : null;
        }
        foreach ($open as $m) {
            $e = $emails->firstWhere('milestone', $m['key']);
            if ($e && ! in_array($e->key, $sent, true)) {
                return $e;
            }
        }

        return null;
    }

    /** Sends the next e-mail now. @return OnboardingSend|null null when there is nothing to send */
    public function sendNext(CompanyOnboarding $o, ?array $reached = null, string $trigger = 'auto'): ?OnboardingSend
    {
        $reached ??= $this->refresh($o);
        $email = $this->nextEmail($o, $reached);

        return $email ? $this->mailer->send($o, $email, $reached, $trigger) : null;
    }

    private function completeIfDone(CompanyOnboarding $o, array $reached): void
    {
        $allDone = ! array_filter(Milestones::steps(), fn ($m) => ! ($reached[$m['key']] ?? false));
        $allSet = OnboardingSend::where('tenant_id', $o->tenant_id)->where('email_key', 'all_set')->where('status', 'sent')->exists();
        if ($allDone && ($allSet || ! OnboardingEmail::where('key', 'all_set')->where('enabled', true)->exists())) {
            $o->forceFill(['state' => 'completed', 'needs_help' => false, 'help_reasons' => null, 'help_since' => null])->save();
        }
    }

    /** Works out why a company might need a hand, and remembers it. */
    public function detectHelp(CompanyOnboarding $o, array $reached): void
    {
        $tenant = $o->tenant;
        $total = count(Milestones::steps());
        $done = count(array_filter(Milestones::steps(), fn ($m) => $reached[$m['key']] ?? false));
        $reasons = [];

        $since = $o->last_progress_at && $o->last_progress_at->gt($o->started_at) ? $o->last_progress_at : $o->started_at;
        if ($done < $total && $since->diffInDays(now()) >= $this->settings->int('stalled_days')) {
            $reasons[] = 'stalled';
        }
        if ($tenant->trial_ends_at && ! $this->plans->state($tenant)['ok'] && $done < 4
            && $tenant->trial_ends_at->diffInDays(now(), false) > -$this->settings->int('trial_help_days')) {            // ends within the window, or has ended
            $reasons[] = 'trial_ending';
        }
        if (CompanySubscription::where('tenant_id', $tenant->getTenantKey())->whereIn('status', ['past_due', 'unpaid'])->exists()) {
            $reasons[] = 'payment_failed';
        }
        $lastSeen = $tenant->last_active_at ?? $o->started_at;
        if ($o->started_at->diffInDays(now()) >= $this->settings->int('inactive_days') && $lastSeen->diffInDays(now()) >= $this->settings->int('inactive_days')) {
            $reasons[] = 'inactive';
        }

        $needs = $reasons !== [];
        $o->forceFill(['needs_help' => $needs, 'help_reasons' => $needs ? $reasons : null, 'help_since' => $needs ? ($o->help_since ?? now()) : null])->save();
    }

    // ---- what the platform can do about a company

    public function pause(CompanyOnboarding $o): void
    {
        $o->update(['state' => 'paused']);
    }

    public function resume(CompanyOnboarding $o): void
    {
        $o->update(['state' => 'active']);
    }

    /** Sets a company's flag aside for a while (the platform has dealt with it). */
    public function handled(CompanyOnboarding $o, int $days = 7): void
    {
        $o->update(['help_snoozed_until' => now()->addDays(max(1, min(60, $days)))]);
    }

    // ---- telling the platform

    /** The companies that ask for a hand now. */
    public function needingHelp()
    {
        return CompanyOnboarding::with('tenant')->where('needs_help', true)->where(fn ($q) => $q->whereNull('help_snoozed_until')->orWhere('help_snoozed_until', '<', now()))->get()
            ->filter(fn ($o) => $o->tenant && $o->tenant->isActive())->values();
    }

    /** The daily summary for the platform's administrators. @return int how many companies it names */
    public function digest(): int
    {
        if (! $this->settings->on('alert_digest')) {
            return 0;
        }
        $list = $this->needingHelp();
        if ($list->isEmpty()) {
            return 0;
        }
        $total = count(Milestones::steps());
        $label = ['stalled' => 'no new step for a while', 'trial_ending' => 'trial ending with little done', 'payment_failed' => 'a payment failed', 'inactive' => 'nobody has signed in'];
        $lines = $list->map(function ($o) use ($total, $label) {
            $done = count(array_filter(array_keys($o->milestones ?? []), fn ($k) => $k !== 'account'));

            return "{$o->tenant->name}: {$done} of {$total} steps, ".implode(', ', array_map(fn ($r) => $label[$r] ?? $r, $o->help_reasons ?? []))."\n".url('/platform/onboarding?company='.$o->tenant_id);
        })->implode("\n\n");

        $this->notifier->mail(PlatformNotifier::ONBOARDING, $list->count().' '.($list->count() === 1 ? 'company needs' : 'companies need').' a hand with onboarding', "These companies could use a hand getting started:\n\n{$lines}\n\nOpen Platform > Onboarding to see each company's steps and what we have sent.");

        return $list->count();
    }
}
