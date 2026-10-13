<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Support\SafeSvg;
use App\Support\Tenancy\Company;
use App\Support\UploadLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        return Inertia::render('Platform/Branding', ['branding' => Company::platformBranding(), 'upload_limit' => UploadLimit::bytes()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme' => ['required', 'in:light,dark,system'],
            'dark_style' => ['sometimes', Rule::in(Company::DARK_STYLES)],
            'privacy_url' => ['sometimes', 'nullable', 'max:255', 'regex:/^https?:\/\/\S+$/i'],
            'legal_url' => ['sometimes', 'nullable', 'max:255', 'regex:/^https?:\/\/\S+$/i'],
            'terms_url' => ['sometimes', 'nullable', 'max:255', 'regex:/^https?:\/\/\S+$/i'],
            'contact_url' => ['sometimes', 'nullable', 'max:255', 'regex:/^(https?:\/\/|mailto:)\S+$/i'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
        ], array_fill_keys(['privacy_url.regex', 'legal_url.regex', 'terms_url.regex', 'contact_url.regex'], 'Enter a full address starting with https:// (the contact link may also be mailto:).'));

        PlatformSetting::put('login.name', $d['name']);
        PlatformSetting::put('login.tagline', trim((string) ($d['tagline'] ?? '')));      // '' hides the tagline
        PlatformSetting::put('login.color', strtolower($d['color']));
        PlatformSetting::put('login.theme', $d['theme']);
        isset($d['dark_style']) && PlatformSetting::put('login.dark_style', $d['dark_style']);
        foreach (Company::FOOTER_FIELDS as $field) {
            array_key_exists($field, $d) && PlatformSetting::put('login.footer.'.$field, trim((string) $d[$field]));         // '' removes it from the page
        }

        return back()->with('success', 'Sign-in page saved.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $path = $this->storeUpload($request, 'logo', ['png', 'jpg', 'jpeg', 'webp'], 2048, image: true);

        $this->deleteLogo();
        PlatformSetting::put('login.logo_path', $path);

        return back()->with('success', 'Logo updated.');
    }

    public function uploadLogoLight(Request $request): RedirectResponse
    {
        $path = $this->storeUpload($request, 'logo', ['png', 'jpg', 'jpeg', 'webp'], 2048, image: true);

        $this->deleteFile('login.logo_light_path');
        PlatformSetting::put('login.logo_light_path', $path);

        return back()->with('success', 'Logo for light backgrounds updated.');
    }

    public function destroyLogoLight(): RedirectResponse
    {
        $this->deleteFile('login.logo_light_path');
        PlatformSetting::put('login.logo_light_path', null);

        return back()->with('success', 'Logo for light backgrounds removed.');
    }

    /** How the logo sits on the sign-in page: bare (a logo made for a dark background) or on a white plate (dark lettering). */
    public function logoStyle(Request $request): RedirectResponse
    {
        $d = $request->validate(['logo_plate' => ['sometimes', 'boolean'], 'logo_mono' => ['sometimes', 'boolean']]);
        isset($d['logo_plate']) && PlatformSetting::put('login.logo_plate', $d['logo_plate'] ? '1' : '0');
        isset($d['logo_mono']) && PlatformSetting::put('login.logo_mono', $d['logo_mono'] ? '1' : '0');

        return back()->with('success', isset($d['logo_mono']) ? ($d['logo_mono'] ? 'The built-in logo is now one colour.' : 'The built-in logo is back in full colour.') : (($d['logo_plate'] ?? false) ? 'The logo now sits on a white plate.' : 'The logo is shown as it is.'));
    }

    public function destroyLogo(): RedirectResponse
    {
        $this->deleteLogo();
        PlatformSetting::put('login.logo_path', null);

        return back()->with('success', 'Logo removed.');
    }

    public function uploadBackground(Request $request): RedirectResponse
    {
        $isVideo = in_array(strtolower((string) $request->file('background')?->getClientOriginalExtension()), ['mp4', 'webm'], true);
        $request->validate(['background' => $isVideo
            ? ['required', 'file', 'mimetypes:video/mp4,video/webm', 'max:25600']
            : ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']], ['background.max' => $isVideo ? 'A background video can be up to 25 MB.' : 'A background image can be up to 5 MB.']);

        $this->deleteFile('login.background_path');
        PlatformSetting::put('login.background_path', $request->file('background')->store('branding', 'platform'));

        return back()->with('success', $isVideo ? 'Background video updated.' : 'Background updated.');
    }

    public function destroyBackground(): RedirectResponse
    {
        $this->deleteFile('login.background_path');
        PlatformSetting::put('login.background_path', null);

        return back()->with('success', 'Background removed.');
    }

    public function background(): HttpResponse
    {
        return $this->serve('login.background_path', Company::BUILT_IN_BACKGROUND);
    }

    /** Public: the sign-in page needs it before anybody is signed in. Always the platform's file, never a company's. */
    public function logo(): HttpResponse
    {
        return $this->serve('login.logo_path', 'brand/orvyn-logo'.$this->monoSuffix().'.svg');
    }

    /** The logo for light backgrounds: public like the other one. */
    public function logoLight(): HttpResponse
    {
        return $this->serve('login.logo_light_path', 'brand/orvyn-logo-dark'.$this->monoSuffix().'.svg');
    }

    public function favicon(): HttpResponse
    {
        return $this->serve('login.favicon_path', 'favicon.svg');
    }

    public function uploadFavicon(Request $request): RedirectResponse
    {
        $path = $this->storeUpload($request, 'favicon', ['png', 'ico', 'webp'], 512, image: false);

        $this->deleteFile('login.favicon_path');
        PlatformSetting::put('login.favicon_path', $path);

        return back()->with('success', 'Favicon updated.');
    }

    public function destroyFavicon(): RedirectResponse
    {
        $this->deleteFile('login.favicon_path');
        PlatformSetting::put('login.favicon_path', null);

        return back()->with('success', 'Favicon removed.');
    }

    // ---- the platform area's own look (Platform > Settings > Platform look) -------------------------------------------------------

    public function look(): Response
    {
        $sign = Company::platformBranding();
        $area = Company::platformAreaBranding();

        return Inertia::render('Platform/Look', [
            'look' => [
                'name' => (string) PlatformSetting::get('platform.name'), 'color' => (string) PlatformSetting::get('platform.color'),
                'name_effective' => $area['name'], 'color_effective' => $area['color'],
                'name_default' => $sign['name'], 'color_default' => $sign['color'],
                'mark_url' => $area['mark_url'], 'mark_custom' => (bool) PlatformSetting::get('platform.logo_path'),
                'favicon_url' => $area['favicon_url'], 'favicon_custom' => (bool) PlatformSetting::get('platform.favicon_path'),
            ],
            'upload_limit' => UploadLimit::bytes(),
        ]);
    }

    /** Empty fields follow the sign-in page's name and colour. */
    public function updateLook(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['nullable', 'string', 'max:60'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        PlatformSetting::put('platform.name', trim((string) ($d['name'] ?? '')) ?: null);
        PlatformSetting::put('platform.color', ! empty($d['color']) ? strtolower($d['color']) : null);

        return back()->with('success', 'Platform look saved.');
    }

    public function uploadLookLogo(Request $request): RedirectResponse
    {
        $path = $this->storeUpload($request, 'logo', ['png', 'jpg', 'jpeg', 'webp'], 2048, image: true);
        $this->deleteFile('platform.logo_path');
        PlatformSetting::put('platform.logo_path', $path);

        return back()->with('success', 'Logo updated.');
    }

    public function destroyLookLogo(): RedirectResponse
    {
        $this->deleteFile('platform.logo_path');
        PlatformSetting::put('platform.logo_path', null);

        return back()->with('success', 'Back to the built-in logo.');
    }

    public function uploadLookFavicon(Request $request): RedirectResponse
    {
        $path = $this->storeUpload($request, 'favicon', ['png', 'ico', 'webp'], 512, image: false);
        $this->deleteFile('platform.favicon_path');
        PlatformSetting::put('platform.favicon_path', $path);

        return back()->with('success', 'Favicon updated.');
    }

    public function destroyLookFavicon(): RedirectResponse
    {
        $this->deleteFile('platform.favicon_path');
        PlatformSetting::put('platform.favicon_path', null);

        return back()->with('success', 'Back to the sign-in page favicon.');
    }

    /** The platform area's own logo mark, else the built-in one. */
    public function lookLogo(): HttpResponse
    {
        return $this->serve('platform.logo_path', 'brand/orvyn-mark.svg');
    }

    /** The platform area's own favicon, else the sign-in page's. */
    public function lookFavicon(): HttpResponse
    {
        return PlatformSetting::get('platform.favicon_path') ? $this->serve('platform.favicon_path') : $this->favicon();
    }

    /** The uploaded file, else the built-in one (under public/) when there is one, else 404. */
    private function serve(string $key, ?string $builtIn = null): HttpResponse
    {
        $path = PlatformSetting::get($key);
        if (! ($path && Storage::disk('platform')->exists($path))) {
            abort_unless($builtIn && is_file(public_path($builtIn)), 404);

            return response()->file(public_path($builtIn), ['Cache-Control' => 'public, max-age=3600'] + $this->svgHeaders($builtIn) + (str_ends_with($builtIn, '.mp4') ? ['Content-Type' => 'video/mp4', 'X-Content-Type-Options' => 'nosniff'] : []));
        }

        // a video is sent as a file so the browser can ask for parts of it (Safari will not play one otherwise)
        if (preg_match('/\.(mp4|webm)$/i', $path, $video)) {
            return response()->file(Storage::disk('platform')->path($path), ['Content-Type' => 'video/'.strtolower($video[1]), 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
        }

        return Storage::disk('platform')->response($path, null, ['Cache-Control' => 'public, max-age=86400'] + $this->svgHeaders($path));
    }

    /**
     * An SVG is served as an image and may never run anything, even when opened on its own: no scripts, nothing loaded from
     * elsewhere, no guessing of the type.
     *
     * @return array<string, string>
     */
    private function svgHeaders(string $path): array
    {
        return str_ends_with(strtolower($path), '.svg')
            ? ['Content-Type' => 'image/svg+xml', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox", 'X-Content-Type-Options' => 'nosniff']
            : [];
    }

    /**
     * Save an uploaded picture and return where. An SVG is cleaned first (see SafeSvg); anything else must be one of the
     * listed raster types.
     *
     * @param  list<string>  $mimes
     */
    private function storeUpload(Request $request, string $field, array $mimes, int $maxKb, bool $image): string
    {
        $file = $request->file($field);

        if ($file && strtolower((string) $file->getClientOriginalExtension()) === 'svg') {
            $request->validate([$field => ['required', 'file', 'max:'.$maxKb]]);
            $clean = SafeSvg::clean((string) file_get_contents($file->getRealPath()));
            if ($clean === null) {
                throw ValidationException::withMessages([$field => 'That SVG could not be read. Save it as a plain SVG without scripts or embedded files.']);
            }
            Storage::disk('platform')->put($path = 'branding/'.Str::random(40).'.svg', $clean);

            return $path;
        }

        $request->validate([$field => ['required', $image ? 'image' : 'file', 'mimes:'.implode(',', $mimes), 'max:'.$maxKb]]);

        return $request->file($field)->store('branding', 'platform');
    }

    private function monoSuffix(): string
    {
        return PlatformSetting::get('login.logo_mono') === '1' ? '-mono' : '';
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
