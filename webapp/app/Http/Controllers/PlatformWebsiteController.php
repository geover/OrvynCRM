<?php

namespace App\Http\Controllers;

use App\Services\Tenancy\WebsiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Platform > Settings > Website: what the public website says that is decided here, and the hook that rebuilds it. */
class PlatformWebsiteController extends Controller
{
    public function show(WebsiteSettings $website): Response
    {
        return Inertia::render('Platform/SettingsWebsite', [
            'website' => $website->current(),
            'feed' => url('/api/v1/public/website'),
            'effective' => $website->publicValues(),
        ]);
    }

    public function save(Request $request, WebsiteSettings $website): RedirectResponse
    {
        $d = $request->validate([
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'business_address' => ['nullable', 'string', 'max:500'],
            'legal_entity' => ['nullable', 'string', 'max:150'],
            'legal_effective' => ['nullable', 'date'],
            'legal_updated' => ['nullable', 'date'],
            'demo_form_url' => ['nullable', 'url:http,https', 'max:300'],
            'build_hook' => ['nullable', 'url:https', 'max:500'],
            'clear_hook' => ['sometimes', 'boolean'],
        ], ['build_hook.url' => 'A build hook is a public https address.', 'demo_form_url.url' => 'Enter the address of the form script, starting with https://.']);

        if (! empty($d['build_hook'])) {
            try {
                $website->assertReachable($d['build_hook']);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['build_hook' => $e->getMessage()]);
            }
        }
        $changed = $website->save($d);

        if ($changed && $website->hook()) {
            $r = $website->rebuild();

            return back()->with('success', 'Saved.'.($r['ok'] ? ' The website is being rebuilt.' : ''))->with('warning', $r['ok'] ? null : 'Saved, but the website was not rebuilt: '.$r['message']);
        }

        return back()->with('success', $changed ? 'Saved. The website picks it up the next time it is built.' : 'Saved.');
    }

    public function rebuild(WebsiteSettings $website): RedirectResponse
    {
        if (! $website->hook()) {
            return back()->withErrors(['build_hook' => 'Save a build hook first.']);
        }
        $r = $website->rebuild();

        return $r['ok'] ? back()->with('success', $r['message']) : back()->with('warning', 'Not rebuilt: '.$r['message']);
    }
}
