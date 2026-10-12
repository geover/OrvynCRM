<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Support\Tenancy\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The look of the sign-in pages (login, company chooser, password reset). It is the platform's alone: set here, shown
 * to everybody, and never changed by a company's own look & feel.
 */
class PlatformBrandingController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Platform/Branding', ['branding' => Company::platformBranding()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme' => ['required', 'in:light,dark,system'],
        ]);

        PlatformSetting::put('login.name', $d['name']);
        PlatformSetting::put('login.tagline', trim((string) ($d['tagline'] ?? '')));      // '' hides the tagline
        PlatformSetting::put('login.color', strtolower($d['color']));
        PlatformSetting::put('login.theme', $d['theme']);

        return back()->with('success', 'Sign-in page saved.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);     // SVG excluded: script injection

        $this->deleteLogo();
        PlatformSetting::put('login.logo_path', $request->file('logo')->store('branding', 'platform'));

        return back()->with('success', 'Logo updated.');
    }

    public function destroyLogo(): RedirectResponse
    {
        $this->deleteLogo();
        PlatformSetting::put('login.logo_path', null);

        return back()->with('success', 'Logo removed.');
    }

    public function uploadBackground(Request $request): RedirectResponse
    {
        $request->validate(['background' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);

        $this->deleteFile('login.background_path');
        PlatformSetting::put('login.background_path', $request->file('background')->store('branding', 'platform'));

        return back()->with('success', 'Background updated.');
    }

    public function destroyBackground(): RedirectResponse
    {
        $this->deleteFile('login.background_path');
        PlatformSetting::put('login.background_path', null);

        return back()->with('success', 'Background removed.');
    }

    public function background(): HttpResponse
    {
        return $this->serve('login.background_path');
    }

    /** Public: the sign-in page needs it before anybody is signed in. Always the platform's file, never a company's. */
    public function logo(): HttpResponse
    {
        return $this->serve('login.logo_path', 'brand/orvyn-logo.svg');
    }

    public function favicon(): HttpResponse
    {
        return $this->serve('login.favicon_path', 'favicon.svg');
    }

    public function uploadFavicon(Request $request): RedirectResponse
    {
        $request->validate(['favicon' => ['required', 'file', 'mimes:png,ico,webp', 'max:512']]);       // SVG excluded: script injection

        $this->deleteFile('login.favicon_path');
        PlatformSetting::put('login.favicon_path', $request->file('favicon')->store('branding', 'platform'));

        return back()->with('success', 'Favicon updated.');
    }

    public function destroyFavicon(): RedirectResponse
    {
        $this->deleteFile('login.favicon_path');
        PlatformSetting::put('login.favicon_path', null);

        return back()->with('success', 'Favicon removed.');
    }

    /** The uploaded file, else the built-in one (under public/) when there is one, else 404. */
    private function serve(string $key, ?string $builtIn = null): HttpResponse
    {
        $path = PlatformSetting::get($key);
        if (! ($path && Storage::disk('platform')->exists($path))) {
            abort_unless($builtIn, 404);

            return response()->file(public_path($builtIn), ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600']);
        }

        return Storage::disk('platform')->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    private function deleteLogo(): void
    {
        $this->deleteFile('login.logo_path');
    }

    private function deleteFile(string $key): void
    {
        if ($old = PlatformSetting::get($key)) {
            Storage::disk('platform')->delete($old);
        }
    }
}
