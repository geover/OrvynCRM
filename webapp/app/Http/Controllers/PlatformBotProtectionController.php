<?php

namespace App\Http\Controllers;

use App\Services\Tenancy\Recaptcha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Platform > Settings > Bot protection: the Google reCAPTCHA v3 keys for the sign-in and sign-up pages. */
class PlatformBotProtectionController extends Controller
{
    public function show(Recaptcha $recaptcha): Response
    {
        return Inertia::render('Platform/SettingsBotProtection', ['recaptcha' => $recaptcha->current(), 'active' => $recaptcha->active()]);
    }

    public function save(Request $request, Recaptcha $recaptcha): RedirectResponse
    {
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'site_key' => ['nullable', 'required_if:enabled,true', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'secret' => ['nullable', 'string', 'max:200'],
            'clear_secret' => ['sometimes', 'boolean'],
            'threshold' => ['required', 'numeric', 'between:0.1,0.9'],
        ], ['site_key.regex' => 'Paste the site key exactly as Google shows it.', 'site_key.required_if' => 'Enter the site key before switching it on.']);

        $recaptcha->save($d);
        if ($d['enabled'] && ! $recaptcha->active()) {
            return back()->with('success', 'Saved, but it stays off until the secret key is saved too.');
        }

        return back()->with('success', $d['enabled'] ? 'Bot protection is on for the sign-in and sign-up pages.' : 'Bot protection is off.');
    }
}
