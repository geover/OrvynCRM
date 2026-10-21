<?php

namespace App\Http\Controllers;

use App\Models\CompanyOnboarding;
use App\Models\OnboardingEmail;
use App\Models\OnboardingSend;
use App\Models\Tenant;
use App\Services\Onboarding\DefaultEmails;
use App\Services\Onboarding\Milestones;
use App\Services\Onboarding\Onboarding;
use App\Services\Onboarding\OnboardingMailer;
use App\Services\Onboarding\OnboardingSettings;
use App\Support\SafeHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Platform > Onboarding: where every new company is on its way, the e-mails that guide it, and the choices behind them. */
class PlatformOnboardingController extends Controller
{
    public const HELP = ['stalled' => 'No new step for a while', 'trial_ending' => 'Trial ending with little done', 'payment_failed' => 'A payment failed', 'inactive' => 'Nobody has signed in'];

    public function __construct(private readonly Onboarding $onboarding, private readonly OnboardingSettings $settings, private readonly OnboardingMailer $mailer) {}

    public function index(Request $request): Response
    {
        DefaultEmails::ensure();
        $steps = Milestones::steps();
        $list = CompanyOnboarding::with('tenant')->orderByDesc('needs_help')->orderByDesc('id')->get()->filter(fn ($o) => $o->tenant)->values();
        $rows = $list->map(fn ($o) => $this->row($o, count($steps)))->all();
        $have = $list->pluck('tenant_id')->all();

        return Inertia::render('Platform/Onboarding', [
            'steps' => array_map(fn ($m) => $m + ['index' => 0], $steps),
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'help' => collect($rows)->where('asks_help', true)->count(),
                'completed' => collect($rows)->where('state', 'completed')->count(),
                'average' => $rows ? (int) round(collect($rows)->avg('percent')) : 0,
                'sent_7d' => OnboardingSend::where('status', 'sent')->where('sent_at', '>=', now()->subDays(7))->count(),
            ],
            'emails' => OnboardingEmail::orderBy('position')->get()->map(fn ($e) => $this->emailRow($e))->all(),
            'settings' => $this->settings->all(),
            'replyingTo' => ($r = $this->settings->replyTo()) ? ['address' => $r[0], 'name' => $r[1]] : null,
            'helpLabels' => self::HELP,
            'unstarted' => Tenant::active()->whereNotIn('id', $have)->orderBy('name')->limit(300)->get(['id', 'name'])->map(fn ($t) => ['id' => $t->getTenantKey(), 'name' => $t->name])->all(),
            'focus' => $request->query('company'),
        ]);
    }

    /** One company in detail: the steps with their dates, what was sent, who the owner is. */
    public function show(Tenant $tenant): JsonResponse
    {
        $o = CompanyOnboarding::where('tenant_id', $tenant->getTenantKey())->firstOrFail();
        $o->setRelation('tenant', $tenant);
        $done = $o->milestones ?? [];
        $reached = array_fill_keys(array_keys($done), true);
        $next = $this->onboarding->nextEmail($o, $reached + ['account' => true]);

        return response()->json(['data' => $this->row($o, count(Milestones::steps())) + [
            'owner' => $this->mailer->owner($tenant),
            'note' => $o->note,
            'steps' => array_map(fn ($m) => ['key' => $m['key'], 'title' => $m['title'], 'done' => isset($done[$m['key']]), 'at' => $done[$m['key']] ?? null], Milestones::all()),
            'sends' => OnboardingSend::where('tenant_id', $o->tenant_id)->orderByDesc('id')->limit(40)->get()->map(fn ($s) => [
                'id' => $s->id, 'key' => $s->email_key, 'subject' => $s->subject, 'to' => $s->to, 'status' => $s->status, 'trigger' => $s->trigger, 'error' => $s->error, 'at' => $s->sent_at?->toIso8601String(),
            ])->all(),
            'next_email' => $next ? ['key' => $next->key, 'title' => $next->title] : null,
        ]]);
    }

    public function start(Tenant $tenant): JsonResponse
    {
        $this->onboarding->start($tenant);

        return response()->json(['ok' => true]);
    }

    public function pause(Tenant $tenant): JsonResponse
    {
        $this->onboarding->pause($this->of($tenant));

        return response()->json(['ok' => true]);
    }

    public function resume(Tenant $tenant): JsonResponse
    {
        $this->onboarding->resume($this->of($tenant));

        return response()->json(['ok' => true]);
    }

    public function handled(Request $request, Tenant $tenant): JsonResponse
    {
        $days = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:60']])['days'] ?? 7;
        $this->onboarding->handled($this->of($tenant), (int) $days);

        return response()->json(['ok' => true]);
    }

    public function note(Request $request, Tenant $tenant): JsonResponse
    {
        $this->of($tenant)->update(['note' => $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /** Reads the company's steps again now (instead of at the next run). */
    public function refresh(Tenant $tenant): JsonResponse
    {
        $o = $this->of($tenant);
        $this->onboarding->refresh($o);
        $this->onboarding->detectHelp($o->fresh(), $this->onboarding->refresh($o->fresh()));

        return response()->json(['ok' => true]);
    }

    /** Sends the next e-mail to the owner now, whatever the gap or the weekly limit say. */
    public function sendNext(Tenant $tenant): JsonResponse
    {
        $send = $this->onboarding->sendNext($this->of($tenant), null, 'manual');
        if (! $send) {
            return response()->json(['message' => 'There is nothing left to send to this company.'], 422);
        }

        return response()->json(['ok' => $send->status === 'sent', 'message' => $send->status === 'sent' ? "Sent “{$send->subject}” to {$send->to}." : 'It could not be sent: '.$send->error]);
    }

    public function updateEmail(Request $request, OnboardingEmail $email): JsonResponse
    {
        $d = $request->validate($this->emailRules());
        $d['body'] = SafeHtml::clean($d['body']) ?? '';
        $email->update($d);

        return response()->json(['data' => $this->emailRow($email->fresh())]);
    }

    /** The e-mail as it would look, with the text as typed (saved or not) and a made-up company at the stage this e-mail is written for. */
    public function previewEmail(Request $request, OnboardingEmail $email): JsonResponse
    {
        $d = $request->validate($this->emailRules());
        $draft = (new OnboardingEmail)->forceFill($d + ['key' => $email->key, 'milestone' => $email->milestone]);
        $mail = $this->mailer->render($draft, $this->mailer->sampleFor($draft));

        return response()->json(['subject' => $mail['subject'], 'html' => $mail['html']]);
    }

    public function testEmail(Request $request, OnboardingEmail $email): JsonResponse
    {
        $to = $request->attributes->get('platform_identity')?->email ?? $request->user()?->email;
        abort_unless($to, 422, 'There is no address to send the test to.');
        $this->mailer->test($email, $to);

        return response()->json(['message' => "A test was sent to {$to}."]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'enabled' => ['boolean'], 'alert_badge' => ['boolean'], 'alert_digest' => ['boolean'], 'reply_to' => ['nullable', 'email', 'max:255'],
            'gap_days' => ['integer', 'between:1,14'], 'max_per_week' => ['integer', 'between:1,7'],
            'stalled_days' => ['integer', 'between:2,60'], 'trial_help_days' => ['integer', 'between:1,30'], 'inactive_days' => ['integer', 'between:2,60'],
        ]);
        $this->settings->save($d);

        return response()->json(['data' => $this->settings->all(), 'replying_to' => ($r = $this->settings->replyTo()) ? ['address' => $r[0], 'name' => $r[1]] : null]);
    }

    /** @return array<string, list<string>> */
    private function emailRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'], 'subject' => ['required', 'string', 'max:200'], 'preheader' => ['nullable', 'string', 'max:200'],
            'headline' => ['required', 'string', 'max:200'], 'icon' => ['nullable', 'string', 'max:16'], 'body' => ['required', 'string', 'max:60000'],
            'button_label' => ['nullable', 'string', 'max:80'], 'button_path' => ['nullable', 'string', 'max:200', 'regex:#^/[^\s]*$#'],
            'tip' => ['nullable', 'string', 'max:400'], 'enabled' => ['boolean'],
            'cc' => ['nullable', 'string', 'max:400', function ($attribute, $value, $fail) {
                $bad = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $value) ?: []), fn ($a) => $a !== '' && ! filter_var($a, FILTER_VALIDATE_EMAIL));
                $bad && $fail('These are not e-mail addresses: '.implode(', ', $bad).'.');
            }],
        ];
    }

    private function of(Tenant $tenant): CompanyOnboarding
    {
        return CompanyOnboarding::where('tenant_id', $tenant->getTenantKey())->with('tenant')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(CompanyOnboarding $o, int $total): array
    {
        $done = collect(array_keys($o->milestones ?? []))->reject(fn ($k) => $k === 'account')->values();
        $next = collect(Milestones::steps())->first(fn ($m) => ! $done->contains($m['key']));

        return [
            'id' => $o->tenant_id, 'name' => $o->tenant->name, 'slug' => $o->tenant->slug, 'state' => $o->state,
            'started_at' => $o->started_at?->toIso8601String(), 'done' => $done->count(), 'total' => $total, 'percent' => (int) round($done->count() / max(1, $total) * 100),
            'reached' => $done->all(), 'next' => $next['title'] ?? null,
            'last_sent_at' => $o->last_sent_at?->toIso8601String(), 'emails_sent' => $o->emails_sent,
            'last_active_at' => $o->tenant->last_active_at?->toIso8601String(), 'trial_ends_at' => $o->tenant->trial_ends_at?->toIso8601String(),
            'asks_help' => $o->asksForHelp(), 'needs_help' => $o->needs_help, 'help_reasons' => $o->help_reasons ?? [], 'help_since' => $o->help_since?->toIso8601String(),
            'snoozed_until' => $o->help_snoozed_until?->isFuture() ? $o->help_snoozed_until->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emailRow(OnboardingEmail $e): array
    {
        return $e->only(['id', 'key', 'milestone', 'title', 'subject', 'preheader', 'headline', 'icon', 'body', 'button_label', 'button_path', 'tip', 'cc', 'enabled', 'position']);
    }
}
