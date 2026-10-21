<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
use App\Models\Setting;
use App\Models\Signup;
use App\Models\Tenant;
use App\Services\Onboarding\Onboarding;
use App\Support\NoticeMail;
use App\Support\Tenancy\Company;
use Illuminate\Support\Str;
use Throwable;

/**
 * Self-service sign-up. The order is deliberate: ask for the e-mail first, prove it is theirs, and only then create anything.
 * Nobody gets a password typed in a form that we keep: they choose it after the link in the e-mail, and the company is
 * created at that moment. Until then a sign-up is one row in the central database and costs nothing.
 */
class Signups
{
    /** Addresses from throw-away inbox services are refused: they are how sign-up forms get abused. */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'sharklasers.com', '10minutemail.com', 'yopmail.com', 'tempmail.com', 'temp-mail.org',
        'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mintemail.com',
    ];

    public const VERIFY_DAYS = 3;

    public const APPROVED_DAYS = 7;

    public function __construct(private TenantProvisioner $provisioner, private PlatformNotifier $notifier, private SignupSettings $settings) {}

    public function blockedAddress(string $email): bool
    {
        return in_array(mb_strtolower((string) Str::after($email, '@')), self::DISPOSABLE, true);
    }

    public function find(string $token): ?Signup
    {
        return Signup::where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Someone asked for a workspace. The visitor is always told to check their e-mail, whatever happens here, so the form cannot be
     * used to find out which addresses already have an account.
     *
     * @param  array{company: string, first_name: string, last_name: string, email: string, phone?: ?string, timezone?: ?string, marketing?: bool}  $d
     */
    public function start(array $d, ?string $ip, ?string $agent): void
    {
        $email = mb_strtolower(trim($d['email']));

        if (AccountUser::where('email', $email)->exists()) {
            $this->mail($email, 'You already have an account', ['There is already an account for this e-mail address, so there is no need to sign up again.', 'Sign in with your password, or reset it if you have forgotten it.'], ['Sign in', url('/login')]);

            return;
        }

        $signup = Signup::where('email', $email)->whereNotIn('status', [Signup::COMPLETED, Signup::REJECTED, Signup::COMPLETING])->latest('id')->first();
        if ($signup?->last_sent_at?->gt(now()->subMinute())) {
            return;                                                  // a double click, or someone hammering the button: one e-mail a minute
        }

        $token = Str::random(48);
        $fields = [
            'company_name' => trim($d['company']), 'first_name' => trim($d['first_name']), 'last_name' => trim($d['last_name']), 'phone' => $d['phone'] ?? null,
            'timezone' => in_array($d['timezone'] ?? null, timezone_identifiers_list(), true) ? $d['timezone'] : null, 'marketing_opt_in' => (bool) ($d['marketing'] ?? false),
            'ip' => $ip, 'user_agent' => $agent ? mb_substr($agent, 0, 255) : null,
            'token_hash' => hash('sha256', $token), 'last_sent_at' => now(), 'expires_at' => now()->addDays(self::VERIFY_DAYS),
        ];
        if ($signup) {
            $signup->update($fields + ['send_count' => $signup->send_count + 1]);                // the same request again: a fresh link, same state
        } else {
            $signup = Signup::create($fields + ['email' => $email, 'status' => Signup::PENDING_EMAIL]);
        }

        $this->sendLink($signup, $token);
    }

    /** "Send it again": a fresh link for a sign-up that is still open. At most one e-mail a minute, and nothing is revealed either way. */
    public function resend(string $email): void
    {
        $signup = Signup::where('email', mb_strtolower(trim($email)))->whereNotIn('status', [Signup::COMPLETED, Signup::REJECTED, Signup::COMPLETING])->latest('id')->first();
        if (! $signup || $signup->last_sent_at?->gt(now()->subMinute())) {
            return;
        }
        $token = Str::random(48);
        $signup->update(['token_hash' => hash('sha256', $token), 'last_sent_at' => now(), 'send_count' => $signup->send_count + 1, 'expires_at' => now()->addDays(self::VERIFY_DAYS)]);
        $this->sendLink($signup, $token);
    }

    /** The e-mail for the state the sign-up is in. */
    private function sendLink(Signup $signup, string $token): void
    {
        $link = url('/signup/'.$token);
        $name = Company::platformBranding()['name'];

        match ($signup->status) {
            Signup::PENDING_APPROVAL => $this->mail($signup->email, "Your request to {$name} is being reviewed", ['Thanks for your interest. Your request is waiting for approval, and we will e-mail you as soon as it has been looked at.']),
            Signup::VERIFIED, Signup::APPROVED => $this->mail($signup->email, "Finish setting up {$signup->company_name}", ['Your e-mail address is confirmed. Choose a password to create your workspace.', 'This link works for '.self::VERIFY_DAYS.' days.'], ['Choose your password', $link]),
            default => $this->mail($signup->email, "Confirm your e-mail for {$name}", ["Hi {$signup->first_name},", "Please confirm your e-mail address to create the workspace for {$signup->company_name}.", 'This link works for '.self::VERIFY_DAYS.' days. If you did not ask for it, you can ignore this e-mail.'], ['Confirm my e-mail', $link]),
        };
    }

    /** The link in the first e-mail was opened. Returns the state the person is now in. */
    public function verify(Signup $signup): Signup
    {
        if ($signup->status === Signup::PENDING_EMAIL) {
            $approval = $this->settings->mode() === SignupSettings::APPROVAL;
            $signup->update(['status' => $approval ? Signup::PENDING_APPROVAL : Signup::VERIFIED, 'verified_at' => now()]);
            $approval && $this->notifier->mail(PlatformNotifier::REQUESTS, "A new workspace is waiting for approval: {$signup->company_name}", "{$signup->fullName()} ({$signup->email}) asked for a workspace called {$signup->company_name}.\n\nApprove or decline it under Platform, Sign-ups:\n".url('/platform/signups'));
        } elseif ($signup->status === Signup::PENDING_APPROVAL && $this->settings->mode() === SignupSettings::OPEN) {
            $signup->update(['status' => Signup::VERIFIED]);        // the platform opened sign-ups while this one was waiting
        }

        return $signup;
    }

    public function approve(Signup $signup): void
    {
        abort_unless($signup->status === Signup::PENDING_APPROVAL, 422, 'That request is not waiting for approval.');
        $token = Str::random(48);
        $signup->update(['status' => Signup::APPROVED, 'token_hash' => hash('sha256', $token), 'decided_at' => now(), 'expires_at' => now()->addDays(self::APPROVED_DAYS)]);
        $this->mail($signup->email, "Your workspace {$signup->company_name} is approved", ["Hi {$signup->first_name},", 'Good news: your request was approved. Choose a password to create your workspace.', 'This link works for '.self::APPROVED_DAYS.' days.'], ['Choose your password', url('/signup/'.$token)]);
    }

    public function reject(Signup $signup, ?string $note = null): void
    {
        abort_unless($signup->status === Signup::PENDING_APPROVAL, 422, 'That request is not waiting for approval.');
        $signup->update(['status' => Signup::REJECTED, 'decided_at' => now(), 'decision_note' => $note ? mb_substr(trim($note), 0, 255) : null]);
        $this->mail($signup->email, 'About your request for a workspace', ["Hi {$signup->first_name},", 'Thank you for your interest. We are not able to set up a workspace for you at the moment.'.($note ? "\n\n".trim($note) : '')]);
    }

    /**
     * The password is chosen: create the company and its administrator. Safe to press twice: only one request gets to claim the sign-up.
     *
     * @throws Throwable
     */
    public function complete(Signup $signup, string $password): Tenant
    {
        $before = $signup->status;
        $claimed = Signup::whereKey($signup->id)->whereIn('status', [Signup::VERIFIED, Signup::APPROVED])->update(['status' => Signup::COMPLETING]);
        abort_unless($claimed, 409, 'This link has already been used.');

        try {
            $tenant = $this->provisioner->createCompany($signup->company_name, $this->slugFor($signup->company_name), ['name' => $signup->fullName(), 'email' => $signup->email, 'password' => $password], trial: true);
            AccountUser::where('email', $signup->email)->first()?->forceFill(['email_verified_at' => now()])->save();       // they proved the address by opening the link
            $signup->timezone && $tenant->run(fn () => Setting::put('app.timezone', $signup->timezone));
            $signup->update(['status' => Signup::COMPLETED, 'tenant_id' => $tenant->getTenantKey(), 'completed_at' => now()]);
        } catch (Throwable $e) {
            $signup->update(['status' => $before]);                  // nothing was lost: the link works again

            throw $e;
        }

        $this->mail($signup->email, "Your workspace {$signup->company_name} is ready", ["Hi {$signup->first_name},", 'Your workspace is ready. Sign in any time with your e-mail address and the password you chose.'], ['Open my workspace', url('/login')]);
        $this->notifier->mail(PlatformNotifier::REQUESTS, "New company signed up: {$signup->company_name}", "{$signup->fullName()} ({$signup->email}) created the workspace {$signup->company_name}.\n\n".url('/platform'));

        try {
            app(Onboarding::class)->start($tenant);            // the getting-started e-mails begin; a problem here never spoils a sign-up
        } catch (Throwable $e) {
            report($e);
        }

        return $tenant;
    }

    /** A free address for the company: its name, or the name with a short suffix when taken. */
    public function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        while (Tenant::where('slug', $slug)->exists() || in_array($slug, ['www', 'app', 'api', 'admin', 'platform', 'login', 'logout', 'static', 'assets', 'mail', 'support', 'signup'], true)) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    /**
     * @param  list<string>  $lines
     * @param  array{0: string, 1: string}|null  $button  [label, address]
     */
    private function mail(string $to, string $subject, array $lines, ?array $button = null): void
    {
        NoticeMail::send($to, $subject, $lines, $button);          // a mail problem must not turn the form into an error page, or reveal who has an account
    }
}
