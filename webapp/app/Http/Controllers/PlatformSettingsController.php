<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Signup;
use App\Models\Tenant;
use App\Services\Tenancy\CallSettings;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\InactiveCompanies;
use App\Services\Tenancy\MailSettings;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Pricing;
use App\Services\Tenancy\Recaptcha;
use App\Services\Tenancy\SignupSettings;
use App\Services\Tenancy\SmsSettings;
use App\Services\Tenancy\Subscriptions;
use App\Support\Tenancy\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Platform settings: who gets e-mailed about requests and messages, and the e-mail server those mails go through. */
class PlatformSettingsController extends Controller
{
    /** The overview: one card per settings area with where it stands right now. */
    public function show(MailSettings $mail, CompanyServices $services, Pricing $pricing, SmsSettings $sms, CallSettings $calls): Response
    {
        $admins = AccountUser::where('is_platform_admin', true)->where('status', 'active')->get();
        $companies = Tenant::get();
        $custom = $mail->current();

        return Inertia::render('Platform/Settings', [
            'pricing' => $pricing->current(),
            'sms' => ['enabled' => $sms->current()['enabled'], 'ready' => $sms->account() !== null, 'from' => $sms->current()['effective']['messaging_service_sid'] ? 'Messaging service' : $sms->current()['effective']['from_number']],
            'calls' => $calls->current(),
            'payments' => ['enabled' => app(PaymentSettings::class)->enabled(), 'mode' => app(PaymentSettings::class)->mode(), 'ready' => app(Subscriptions::class)->available()],
            'plans' => ['trial_months' => app(Plans::class)->trialMonths(), 'priced' => collect(app(Plans::class)->tiers())->whereNotNull('price')->count(), 'tiers' => count(config('plans.tiers')), 'trials_ending' => Tenant::whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now())->count()],
            'inactivity' => ['enabled' => app(InactiveCompanies::class)->enabled(), 'days' => app(InactiveCompanies::class)->days(), 'flagged' => Tenant::active()->whereNotNull('inactive_since')->count()],
            'recaptcha' => ['active' => app(Recaptcha::class)->active(), 'enabled' => app(Recaptcha::class)->current()['enabled']],
            'signup' => ['mode' => app(SignupSettings::class)->mode(), 'waiting' => Signup::where('status', Signup::PENDING_APPROVAL)->count()],
            'platform_look' => collect(Company::platformAreaBranding())->only(['name', 'color'])->all(),
            'branding' => collect(Company::platformBranding())->only(['name', 'color', 'theme', 'dark_style'])->all() + ['custom_logo' => ! Company::platformBranding()['logo_is_default'], 'custom_background' => (bool) Company::platformBranding()['background_url']],
            'notifications' => [
                'administrators' => $admins->count(), 'requests' => $admins->where('notify_requests', true)->count(),
                'messages' => $admins->where('notify_messages', true)->count(), 'automation_failures' => $admins->where('notify_automation_failures', true)->count(),
                'inactive_companies' => $admins->where('notify_inactive_companies', true)->count(),
            ],
            'mail' => ['custom' => $custom['enabled'] && $custom['host'] !== '', 'host' => $custom['enabled'] && $custom['host'] !== '' ? $custom['host'] : $mail->envDefaults()['host'], 'from' => $custom['from_address'] ?: $mail->envDefaults()['from_address']],
            'services' => [
                'companies' => $companies->count(),
                'paused' => $companies->filter(fn (Tenant $t) => collect($services->states($t))->contains(fn ($s) => ! $s['enabled']))->count(),
            ],
        ]);
    }

    public function notificationsPage(Request $request): Response
    {
        $me = $request->attributes->get('platform_identity');

        return Inertia::render('Platform/SettingsNotifications', [
            'administrators' => AccountUser::where('is_platform_admin', true)->orderBy('name')->get()->map(fn (AccountUser $a) => [
                'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'status' => $a->status, 'is_me' => $a->is($me),
                'notify_requests' => (bool) $a->notify_requests, 'notify_messages' => (bool) $a->notify_messages,
                'notify_automation_failures' => (bool) $a->notify_automation_failures, 'notify_inactive_companies' => (bool) $a->notify_inactive_companies, 'notify_email' => $a->notify_email,
            ])->values(),
        ]);
    }

    public function mailPage(Request $request, MailSettings $mail): Response
    {
        return Inertia::render('Platform/SettingsMail', ['sendgrid' => ['url' => $mail->sendgridUrl(), 'key_set' => $mail->sendgridVerificationKeySet(), 'key_from_env' => $mail->sendgridKeyFromEnv()], 'mail' => $mail->current(), 'env' => $mail->envDefaults(), 'my_email' => $request->attributes->get('platform_identity')->email]);
    }

    public function notifications(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'administrators' => ['required', 'array', 'max:200'],
            'administrators.*.id' => ['required', 'integer'],
            'administrators.*.notify_requests' => ['required', 'boolean'],
            'administrators.*.notify_messages' => ['required', 'boolean'],
            'administrators.*.notify_automation_failures' => ['sometimes', 'boolean'],
            'administrators.*.notify_inactive_companies' => ['sometimes', 'boolean'],
            'administrators.*.notify_email' => ['nullable', 'email', 'max:255'],
        ], ['administrators.*.notify_email.email' => 'Enter a valid e-mail address, or leave it empty to use the administrator\'s own address.']);

        foreach ($d['administrators'] as $row) {
            AccountUser::where('is_platform_admin', true)->whereKey($row['id'])->update([
                'notify_requests' => $row['notify_requests'], 'notify_messages' => $row['notify_messages'],
                'notify_automation_failures' => $row['notify_automation_failures'] ?? false,
                'notify_inactive_companies' => $row['notify_inactive_companies'] ?? false,
                'notify_email' => trim((string) ($row['notify_email'] ?? '')) ?: null,
            ]);
        }

        return back()->with('success', 'Notification settings saved.');
    }

    public function mail(Request $request, MailSettings $mail): RedirectResponse
    {
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'clear_password' => ['sometimes', 'boolean'],
            'scheme' => ['required', Rule::in(['smtp', 'smtps'])],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);
        $mail->save($d);
        $this->restartWorkers();

        return back()->with('success', $d['enabled'] ? 'Saved. E-mails now go through this server.' : 'Saved. E-mails use the server from the .env file.');
    }

    /** Back to the server in the .env file: the saved host, port, login, password and sender are all cleared. */
    public function resetMail(MailSettings $mail): RedirectResponse
    {
        $mail->reset();
        $this->restartWorkers();

        return back()->with('success', 'Reset. E-mails use the server from the .env file again.');
    }

    /** SendGrid's verification key (optional): with it, event reports must be signed by SendGrid. */
    public function sendgrid(Request $request, MailSettings $mail): RedirectResponse
    {
        $d = $request->validate(['verification_key' => ['nullable', 'string', 'max:600', 'regex:/^[A-Za-z0-9+\/=\s]*$/']], ['verification_key.regex' => 'Paste the verification key exactly as SendGrid shows it.']);
        $mail->saveSendgridVerificationKey($d['verification_key'] ?? null);

        return back()->with('success', $mail->sendgridVerificationKeySet() ? 'Saved. Event reports must now be signed by SendGrid.' : 'Saved. Event reports are accepted on the secret in the address alone.');
    }

    /** A new secret for the event address; paste the new address into SendGrid. */
    public function rotateSendgrid(MailSettings $mail): RedirectResponse
    {
        $mail->rotateSendgridToken();

        return back()->with('success', 'New event address created. Paste it into SendGrid: the old one no longer works.');
    }

    /** Send a test e-mail through whatever is saved, and report the server's own error when it fails. */
    public function test(Request $request, MailSettings $mail): RedirectResponse
    {
        $d = $request->validate(['to' => ['required', 'email']]);
        PlatformSetting::forgetCache();
        $mail->apply();
        try {
            Mail::raw('This is a test e-mail from the platform settings. If you can read it, the e-mail server works.', fn ($m) => $m->to($d['to'])->subject('Test e-mail'));
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['test' => 'The server refused it: '.str($e->getMessage())->squish()->limit(300)]);
        }

        return back()->with('success', "Test e-mail sent to {$d['to']}.");
    }

    /** Queue workers keep the settings they started with; ask them to restart so they pick up the new server. */
    private function restartWorkers(): void
    {
        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            // no cache store for the signal: workers pick the change up when they next restart
        }
    }
}
