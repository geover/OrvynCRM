<?php

namespace App\Services\Onboarding;

use App\Models\CompanyOnboarding;
use App\Models\OnboardingEmail;
use App\Models\OnboardingSend;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SafeHtml;
use App\Support\Tenancy\Company;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Writes an onboarding e-mail for one company: the platform's text with the company's own details, set into the designed layout, and sends it to the company's owner. */
class OnboardingMailer
{
    /** The person who created the company: its first administrator. @return array{name: string, email: string}|null */
    public function owner(Tenant $tenant): ?array
    {
        return $tenant->run(function () {
            $u = User::where('role', 'admin')->where('is_platform_support', false)->orderBy('id')->first(['name', 'email']);

            return $u ? ['name' => $u->name, 'email' => $u->email] : null;
        });
    }

    /**
     * Everything an e-mail can say about a company: the merge values, and what the layout needs.
     *
     * @param  array<string, bool>  $reached
     * @return array<string, mixed>
     */
    public function context(CompanyOnboarding $o, array $reached): array
    {
        $tenant = $o->tenant;
        $owner = $this->owner($tenant) ?? ['name' => 'there', 'email' => ''];

        return $this->build($tenant->name, $owner['name'], $reached, (int) $o->started_at->diffInDays(now()), $tenant->trial_ends_at ? max(0, (int) ceil(now()->diffInHours($tenant->trial_ends_at, false) / 24)) : null, route('onboarding.stop', $o->unsubscribe_token));
    }

    /** A made-up company part-way through, for the platform's preview. @return array<string, mixed> */
    public function sample(): array
    {
        return $this->build('Northwind Traders', 'Maria Santos', ['account' => true, 'contacts' => true, 'data' => true], 4, 10, null);
    }

    /**
     * A made-up company at the stage this e-mail is written for: the steps before its own are done and its own is next (the welcome: nothing yet; the last one: everything),
     * so the platform sees each e-mail with the progress a real company would have when it receives it.
     *
     * @return array<string, mixed>
     */
    public function sampleFor(OnboardingEmail $email): array
    {
        $reached = ['account' => true];
        foreach (Milestones::all() as $m) {
            if ($m['key'] === $email->milestone) {
                break;
            }
            $reached[$m['key']] = $email->key !== 'welcome';
        }
        $email->key === 'all_set' && $reached = array_fill_keys(array_column(Milestones::all(), 'key'), true);

        return $this->build('Northwind Traders', 'Maria Santos', array_filter($reached), 4, 10, null);
    }

    /** @param  array<string, bool>  $reached */
    private function build(string $company, string $ownerName, array $reached, int $days, ?int $trialLeft, ?string $unsubscribe): array
    {
        $brand = Company::platformBranding();
        $steps = [];
        $next = null;
        foreach (Milestones::all() as $m) {
            $done = (bool) ($reached[$m['key']] ?? false);
            $isNext = ! $done && $next === null;
            $isNext && $next = $m;
            $steps[] = ['key' => $m['key'], 'short' => $m['short'], 'title' => $m['title'], 'done' => $done, 'next' => $isNext];
        }
        $total = count(Milestones::steps());
        $done = count(array_filter(Milestones::steps(), fn ($m) => $reached[$m['key']] ?? false));
        $accent = $this->hex($brand['color'] ?? '#4f46e5');
        $first = trim(explode(' ', trim($ownerName))[0] ?? '') ?: 'there';

        return [
            'brand' => $brand, 'accent' => $accent, 'accentDark' => $this->shade($accent, -0.3),
            'company' => $company, 'steps' => $steps, 'next' => $next, 'unsubscribeUrl' => $unsubscribe,
            'progress' => ['done' => $done, 'total' => $total, 'percent' => (int) round($done / max(1, $total) * 100)],
            'values' => [
                'owner.first_name' => $first, 'owner.name' => $ownerName ?: 'there', 'company.name' => $company, 'app.name' => $brand['name'] ?? config('app.name'), 'app.url' => url('/'),
                'progress.done' => (string) $done, 'progress.total' => (string) $total, 'progress.percent' => (string) (int) round($done / max(1, $total) * 100),
                'next.title' => $next['title'] ?? '', 'next.nudge' => $next['nudge'] ?? '', 'days.since_signup' => (string) $days, 'trial.days_left' => $trialLeft === null ? '' : (string) $trialLeft,
            ],
        ];
    }

    /** @param  array<string, string>  $values */
    public function merge(?string $text, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/i', fn ($m) => e($values[strtolower($m[1])] ?? ''), (string) $text);
    }

    /**
     * @param  array<string, mixed>  $c  from context() or sample()
     * @return array{subject: string, html: string, text: string}
     */
    public function render(OnboardingEmail $email, array $c): array
    {
        $values = $c['values'];
        $plain = fn (?string $s) => html_entity_decode($this->merge($s, $values), ENT_QUOTES | ENT_HTML5);
        $next = $c['next'] ?? null;
        $data = $c + [
            'subject' => $plain($email->subject), 'preheader' => $plain($email->preheader), 'headline' => $plain($email->headline), 'icon' => trim((string) $email->icon),
            'bodyHtml' => SafeHtml::clean($this->merge($email->body, $values)) ?? '',
            'tip' => $plain($email->tip), 'buttonLabel' => $plain($email->button_label),
            'buttonUrl' => url($email->button_path ?: ($next['path'] ?? '/')),
        ];
        $data['textBody'] = trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags(preg_replace('#</(p|li|h[1-6])>#i', "\n", str_replace(['<br>', '<br/>', '<br />'], "\n", $data['bodyHtml']))), ENT_QUOTES | ENT_HTML5)));

        return ['subject' => $data['subject'], 'html' => view('mail.onboarding', $data)->render(), 'text' => view('mail.onboarding-text', $data)->render()];
    }

    /** Sends one e-mail to the company's owner and writes it down. A mail problem never throws: it is recorded as failed. */
    public function send(CompanyOnboarding $o, OnboardingEmail $email, array $reached, string $trigger = 'auto'): OnboardingSend
    {
        $owner = $this->owner($o->tenant);
        $mail = $this->render($email, $this->context($o, $reached));
        $record = ['tenant_id' => $o->tenant_id, 'email_key' => $email->key, 'to' => $owner['email'] ?? '', 'subject' => $mail['subject'], 'trigger' => $trigger, 'sent_at' => now()];

        if (! $owner || $owner['email'] === '') {
            return OnboardingSend::create($record + ['status' => 'failed', 'error' => 'The company has no owner with an e-mail address.']);
        }
        try {
            $this->deliver($owner['email'], $mail, '', $this->copies($email->cc, $owner['email']), route('onboarding.stop', $o->unsubscribe_token));
            $o->forceFill(['last_sent_at' => now(), 'emails_sent' => $o->emails_sent + 1])->save();

            return OnboardingSend::create($record + ['status' => 'sent']);
        } catch (Throwable $e) {
            report($e);

            return OnboardingSend::create($record + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** A test of an e-mail, with a made-up company, to one address. */
    public function test(OnboardingEmail $email, string $to): void
    {
        $this->deliver($to, $this->render($email, $this->sampleFor($email)), '[Test] ');
    }

    /**
     * The addresses that get a copy: the ones typed in the e-mail's CC field that really are addresses (at most five, the owner never twice).
     *
     * @return list<string>
     */
    public function copies(?string $cc, ?string $owner = null): array
    {
        $list = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $cc) ?: []), fn ($a) => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) && strcasecmp($a, (string) $owner) !== 0);

        return array_slice(array_values(array_unique($list)), 0, 5);
    }

    /**
     * @param  array{subject: string, html: string, text: string}  $mail
     * @param  list<string>  $cc
     * @param  string|null  $unsubscribe  the address that stops the series: sent as the one-click unsubscribe header mail programs show as their own "Unsubscribe" button (RFC 8058)
     */
    private function deliver(string $to, array $mail, string $prefix = '', array $cc = [], ?string $unsubscribe = null): void
    {
        $replyTo = app(OnboardingSettings::class)->replyTo();
        Mail::send([], [], function ($m) use ($to, $mail, $prefix, $cc, $unsubscribe, $replyTo) {
            $m->to($to)->subject($prefix.$mail['subject'])->html($mail['html'])->text($mail['text']);
            $cc && $m->cc($cc);
            $replyTo && $m->replyTo($replyTo[0], $replyTo[1] ?: null);
            if ($unsubscribe) {
                $headers = $m->getSymfonyMessage()->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', "<{$unsubscribe}>");
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });
    }

    private function hex(string $c): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : '#4f46e5';
    }

    /** Lighter (positive) or darker (negative) by a share. */
    private function shade(string $hex, float $by): string
    {
        $rgb = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $to = $by < 0 ? 0 : 255;

        return '#'.implode('', array_map(fn ($v) => str_pad(dechex((int) round($v + ($to - $v) * abs($by))), 2, '0', STR_PAD_LEFT), $rgb));
    }
}
