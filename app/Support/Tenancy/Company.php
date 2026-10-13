<?php

namespace App\Support\Tenancy;

use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Tenancy\Recaptcha;
use App\Services\Tenancy\SignupSettings;
use Illuminate\Http\Request;

/** Where the current request/job is running: inside one company's database, or only in the central one. */
final class Company
{
    /** The looks of the dark theme (see resources/js/lib/theme.js). */
    public const DARK_STYLES = ['navy', 'graphite', 'midnight', 'black', 'dim', 'mocha'];

    /** True when company data (settings, contacts, users...) can be read: a company is open, or single-database mode. */
    public static function isOpen(): bool
    {
        return tenancy()->initialized || (bool) config('app.single_database');
    }

    /** The links and business address under the sign-in page's left panel; each is empty until the platform sets it. */
    public const FOOTER_FIELDS = ['privacy_url', 'legal_url', 'terms_url', 'contact_url', 'address'];

    /** @return array{privacy_url: string, legal_url: string, terms_url: string, contact_url: string, address: string} */
    public static function signInFooter(): array
    {
        return collect(self::FOOTER_FIELDS)->mapWithKeys(fn (string $key) => [$key => (string) PlatformSetting::get('login.footer.'.$key)])->all();
    }

    /** The built-in sign-in background: a short looping video under public/. */
    public const BUILT_IN_BACKGROUND = 'brand/login-background.mp4';

    /** A short fingerprint of a built-in file under public/, so a changed logo is fetched again instead of coming from the browser's cache. */
    private static function builtInVersion(string $file): string
    {
        $path = public_path($file);

        return 'default-'.(is_file($path) ? substr(md5((string) filemtime($path).filesize($path)), 0, 7) : 'x');
    }

    public static function current(): ?Tenant
    {
        return tenancy()->initialized ? tenant() : null;
    }

    /**
     * The look of the sign-in, company chooser, password and platform pages. Set on the platform's "Sign-in page" screen
     * and never affected by a company's own look & feel.
     */
    public static function platformBranding(): array
    {
        $logo = PlatformSetting::get('login.logo_path');
        $favicon = PlatformSetting::get('login.favicon_path');

        return [
            'name' => PlatformSetting::get('login.name') ?: config('app.name'),
            'footer' => self::signInFooter(),
            // closed | open | approval: whether the sign-in page offers "Create an account"
            'signup' => app(SignupSettings::class)->mode(),
            // the reCAPTCHA v3 site key (public by design) while bot protection is on, else null
            'recaptcha_site_key' => app(Recaptcha::class)->siteKey(),
            'tagline' => PlatformSetting::get('login.tagline', 'Build. Connect. Automate.'),      // '' (cleared) hides it
            'color' => PlatformSetting::get('login.color') ?: '#4f46e5',
            'sidebar_color' => null,
            // light | dark | system (follow the visitor's device): one choice for every visitor of the sign-in pages
            'theme' => in_array($theme = PlatformSetting::get('login.theme'), ['light', 'dark', 'system'], true) ? $theme : 'light',
            // which dark style the sign-in pages use when they are dark (the same list as the app's dark styles)
            'dark_style' => in_array($style = PlatformSetting::get('login.dark_style'), self::DARK_STYLES, true) ? $style : 'navy',
            // always set: the platform's own logo (the built-in one until a platform administrator uploads another) is the
            // default for every company that has no logo of its own
            'logo_url' => url('/brand/logo').'?v='.($logo ? substr(md5($logo), 0, 8) : self::builtInVersion('brand/orvyn-logo'.(PlatformSetting::get('login.logo_mono') === '1' ? '-mono' : '').'.svg')),
            'logo_is_default' => ! $logo,
            // the logo for LIGHT backgrounds (a light sidebar, light pages); `logo_url` above is the one for dark backgrounds
            'logo_light_url' => url('/brand/logo-light').'?v='.(($light = PlatformSetting::get('login.logo_light_path')) ? substr(md5($light), 0, 8) : self::builtInVersion('brand/orvyn-logo-dark'.(PlatformSetting::get('login.logo_mono') === '1' ? '-mono' : '').'.svg')),
            'logo_light_is_default' => ! $light,
            // a logo with dark lettering is shown on a white plate, so it stays readable over a dark background
            'logo_plate' => PlatformSetting::get('login.logo_plate') === '1',
            // the built-in logos come in full colour or one colour (white on dark, ink on light)
            'logo_mono' => PlatformSetting::get('login.logo_mono') === '1',
            'favicon_url' => $favicon ? url('/brand/favicon').'?v='.substr(md5($favicon), 0, 8) : url('/favicon.svg').'?v='.self::builtInVersion('favicon.svg'),
            'favicon_is_default' => ! $favicon,
            // an uploaded picture or video; else the built-in looping video (when its file is there); else the drawn illustration
            'background_url' => ($bg = PlatformSetting::get('login.background_path')) ? url('/brand/background').'?v='.substr(md5($bg), 0, 8)
                : (is_file(public_path(self::BUILT_IN_BACKGROUND)) ? url('/brand/background').'?v='.self::builtInVersion(self::BUILT_IN_BACKGROUND) : null),
            'background_is_default' => ! $bg,
            // image | video: a video plays silently on a loop behind the sign-in page
            'background_type' => ! $bg || in_array(strtolower(pathinfo((string) $bg, PATHINFO_EXTENSION)), ['mp4', 'webm'], true) ? 'video' : 'image',
            'timezone' => config('app.timezone'),
        ];
    }

    /** The favicon for the page being rendered: the open company's own, else the platform's. */
    public static function pageFaviconUrl(Request $request): string
    {
        $platform = self::platformBranding()['favicon_url'];
        if (! self::isOpen() || self::isSignInPage($request) || self::isPlatformArea($request)) {
            return $platform;
        }
        $path = Setting::get('app.favicon_path');

        return $path ? url('/branding/favicon').'?v='.substr(md5($path), 0, 8) : $platform;
    }

    /** The platform area: its look and settings are the platform's, never a company's. */
    public static function isPlatformArea(Request $request): bool
    {
        return $request->is('platform', 'platform/*');
    }

    /** Pages everyone sees before (or between) companies: they always carry the platform's look. */
    public static function isSignInPage(Request $request): bool
    {
        return $request->is('login', 'forgot-password', 'reset-password', 'reset-password/*', 'choose-company', 'signup', 'signup/*');
    }
}
