<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\MailSettings;
use App\Services\Tenancy\Pricing;
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
    public function show(MailSettings $mail, CompanyServices $services, Pricing $pricing): Response
    {
        $admins = AccountUser::where('is_platform_admin', true)->where('status', 'active')->get();
        $companies = Tenant::get();
        $custom = $mail->current();

        return Inertia::render('Platform/Settings', [
            'pricing' => $pricing->current(),
            'branding' => collect(Company::platformBranding())->only(['name', 'color', 'theme'])->all() + ['custom_logo' => ! Company::platformBranding()['logo_is_default'], 'custom_background' => (bool) Company::platformBranding()['background_url']],
            'notifications' => [
                'administrators' => $admins->count(), 'requests' => $admins->where('notify_requests', true)->count(),
                'messages' => $admins->where('notify_messages', true)->count(), 'automation_failures' => $admins->where('notify_automation_failures', true)->count(),
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
                'notify_automation_failures' => (bool) $a->notify_automation_failures, 'notify_email' => $a->notify_email,
            ])->values(),
        ]);
    }

    public function mailPage(Request $request, MailSettings $mail): Response
    {
        return Inertia::render('Platform/SettingsMail', ['mail' => $mail->current(), 'env' => $mail->envDefaults(), 'my_email' => $request->attributes->get('platform_identity')->email]);
    }

    public function notifications(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'administrators' => ['required', 'array', 'max:200'],
            'administrators.*.id' => ['required', 'integer'],
            'administrators.*.notify_requests' => ['required', 'boolean'],
            'administrators.*.notify_messages' => ['required', 'boolean'],
            'administrators.*.notify_automation_failures' => ['sometimes', 'boolean'],
            'administrators.*.notify_email' => ['nullable', 'email', 'max:255'],
        ], ['administrators.*.notify_email.email' => 'Enter a valid e-mail address, or leave it empty to use the administrator\'s own address.']);

        foreach ($d['administrators'] as $row) {
            AccountUser::where('is_platform_admin', true)->whereKey($row['id'])->update([
                'notify_requests' => $row['notify_requests'], 'notify_messages' => $row['notify_messages'],
                'notify_automation_failures' => $row['notify_automation_failures'] ?? false,
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
