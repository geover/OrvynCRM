<?php

namespace App\Support\Tenancy;

use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Http\Request;

/** Where the current request/job is running: inside one company's database, or only in the central one. */
final class Company
{
    /** True when company data (settings, contacts, users...) can be read: a company is open, or single-database mode. */
    public static function isOpen(): bool
    {
        return tenancy()->initialized || (bool) config('app.single_database');
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
            'tagline' => PlatformSetting::get('login.tagline', 'Build. Connect. Automate.'),      // '' (cleared) hides it
            'color' => PlatformSetting::get('login.color') ?: '#4f46e5',
            'sidebar_color' => null,
            // light | dark | system (follow the visitor's device): one choice for every visitor of the sign-in pages
            'theme' => in_array($theme = PlatformSetting::get('login.theme'), ['light', 'dark', 'system'], true) ? $theme : 'light',
            // always set: the platform's own logo (the built-in one until a platform administrator uploads another) is the
            // default for every company that has no logo of its own
            'logo_url' => url('/brand/logo').'?v='.($logo ? substr(md5($logo), 0, 8) : 'default'),
            'logo_is_default' => ! $logo,
            'favicon_url' => $favicon ? url('/brand/favicon').'?v='.substr(md5($favicon), 0, 8) : url('/favicon.svg'),
            'favicon_is_default' => ! $favicon,
            'background_url' => ($bg = PlatformSetting::get('login.background_path')) ? url('/brand/background').'?v='.substr(md5($bg), 0, 8) : null,
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
        return $request->is('login', 'forgot-password', 'reset-password', 'reset-password/*', 'choose-company');
    }
}
