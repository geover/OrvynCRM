<?php

namespace App\Http\Controllers;

use App\Services\Auth\SignInSecurity;
use App\Services\Auth\SocialProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Platform > Settings > Sign-in security: who must use two-step verification, which identity providers people may sign in with, and passkeys. */
class PlatformSecurityController extends Controller
{
    public function show(SignInSecurity $security, SocialProviders $providers): Response
    {
        return Inertia::render('Platform/SettingsSecurity', [
            'policy' => $security->policy(),
            'adoption' => $security->adoption(),
            'passkeys' => $security->passkeysEnabled(),
            'social' => collect($providers->keys())->map(fn (string $key) => $providers->config($key))->values(),
        ]);
    }

    public function save(Request $request, SignInSecurity $security): RedirectResponse
    {
        $d = $request->validate(['policy' => ['required', Rule::in(SignInSecurity::POLICIES)]]);
        $security->savePolicy($d['policy']);

        return back()->with('success', match ($d['policy']) {
            'optional' => 'Two-step verification is optional for everybody.',
            default => 'Saved. People it applies to are asked to set it up the next time they open a page.',
        });
    }

    public function savePasskeys(Request $request, SignInSecurity $security): RedirectResponse
    {
        $security->savePasskeys($request->validate(['enabled' => ['required', 'boolean']])['enabled']);

        return back()->with('success', $request->boolean('enabled') ? 'Passkeys are on: people can add one on their sign-in security page.' : 'Passkeys are off.');
    }

    public function saveSocial(Request $request, string $provider, SocialProviders $providers): RedirectResponse
    {
        abort_unless($providers->exists($provider), 404);
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'client_id' => ['nullable', 'required_if:enabled,true', 'string', 'max:300'],
            'client_secret' => ['nullable', 'string', 'max:2000'],
            'clear_secret' => ['sometimes', 'boolean'],
            'tenant' => [$provider === 'microsoft' ? 'required_if:enabled,true' : 'nullable', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'issuer' => [$provider === 'oidc' ? 'required_if:enabled,true' : 'nullable', 'nullable', 'url:https', 'max:300'],
            'label' => ['nullable', 'string', 'max:40'],
        ], [
            'client_id.required_if' => 'Enter the client ID before switching it on.', 'tenant.required_if' => 'Enter the directory (tenant) ID before switching it on.',
            'issuer.required_if' => 'Enter the provider\'s address before switching it on.', 'issuer.url' => 'The address must start with https://.',
            'tenant.regex' => 'Paste the directory (tenant) ID or domain exactly as Microsoft shows it.',
        ]);
        $providers->save($provider, $d);

        if ($d['enabled'] && ! $providers->active($provider)) {
            return back()->with('success', 'Saved, but it stays off until the client secret is saved too.');
        }

        return back()->with('success', $d['enabled'] ? $providers->name($provider).' sign-in is on.' : $providers->name($provider).' sign-in is off.');
    }
}
