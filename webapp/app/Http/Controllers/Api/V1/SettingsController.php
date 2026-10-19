<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Tenancy\WebhookToken;
use App\Support\Tenancy\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** App Details + Look & Feel + support emails + email-sync domain exclusions (old AdminSettings cards). */
class SettingsController extends Controller
{
    public const DEFAULT_TAGLINE = 'Build. Connect. Automate.';

    public static function branding(): array
    {
        if (! Company::isOpen()) {
            return Company::platformBranding();         // sign-in page, company chooser, platform area
        }

        // a picture whose file is gone (moved or lost storage) counts as none: the platform's is shown instead of a broken image
        $logo = self::existingFile(Setting::get('app.logo_path'));
        $favicon = self::existingFile(Setting::get('app.favicon_path'));

        return [
            'name' => Setting::get('app.name', config('app.name')),
            'tagline' => Setting::get('app.tagline', self::DEFAULT_TAGLINE),   // '' (cleared by an admin) is respected: it hides the tagline
            'color' => Setting::get('app.ui_colour', '#4f46e5'),
            'sidebar_color' => Setting::get('app.sidebar_colour'),        // null: the sidebar follows the theme colour
            'logo_url' => $logo ? url('/branding/logo').'?v='.substr(md5($logo), 0, 8) : Company::platformBranding()['logo_url'],
            'logo_is_default' => ! $logo,
            'favicon_url' => $favicon ? url('/branding/favicon').'?v='.substr(md5($favicon), 0, 8) : Company::platformBranding()['favicon_url'],
            'favicon_is_default' => ! $favicon,
            'timezone' => Setting::get('app.timezone', config('app.timezone')),
            'gravatar' => (bool) Setting::get('app.gravatar', true),
        ];
    }

    private static function existingFile(?string $path): ?string
    {
        return $path && Storage::disk('public')->exists($path) ? $path : null;
    }

    public function show(Request $request): JsonResponse
    {
        $data = self::branding() + ['email_format' => Setting::get('app.email_format', 'ContactId@'.parse_url(config('app.url'), PHP_URL_HOST))];
        if ($request->user()->isAdmin()) {
            $data += [
                'internal_support_emails' => Setting::get('support.internal_emails', []),
                'excluded_email_domains' => Setting::get('email.excluded_domains', []),
                'app_url' => config('app.url'),
                'api_docs_url' => url('/docs/api'),
                'webhook_urls' => tenancy()->initialized ? WebhookToken::urls() : null,   // per-company callback URLs for SendGrid / Twilio
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'timezone' => ['sometimes', 'timezone:all'],
            'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sidebar_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'name' => ['sometimes', 'string', 'max:120'],
            'gravatar' => ['sometimes', 'boolean'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email_format' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z]+@[A-Za-z0-9.\-]+$/'],   // local part + hostname (no port)
            'internal_support_emails' => ['sometimes', 'array', 'max:50'],
            'internal_support_emails.*' => ['email:rfc'],
            'excluded_email_domains' => ['sometimes', 'array', 'max:500'],
            'excluded_email_domains.*' => ['string', 'max:255', 'regex:/^(?!-)[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/'],
        ]);

        $map = ['timezone' => 'app.timezone', 'color' => 'app.ui_colour', 'sidebar_color' => 'app.sidebar_colour', 'name' => 'app.name', 'tagline' => 'app.tagline', 'gravatar' => 'app.gravatar', 'email_format' => 'app.email_format',
            'internal_support_emails' => 'support.internal_emails', 'excluded_email_domains' => 'email.excluded_domains'];
        foreach ($d as $k => $v) {
            // ConvertEmptyStringsToNull turns a cleared tagline into null; store '' so it stays cleared instead of reverting to the default
            $k === 'tagline' && $v = trim((string) $v);
            $k === 'sidebar_color' && $v = $v ? strtolower($v) : null;
            Setting::put($map[$k], is_array($v) ? array_values(array_unique(array_map('strtolower', $v))) : $v);
        }

        return $this->show($request);
    }

    /** Replace the company's inbound webhook token (the old callback URLs stop working at once). */
    public function rotateWebhookToken(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && tenancy()->initialized, 403);
        WebhookToken::rotate();

        return response()->json(['data' => ['webhook_urls' => WebhookToken::urls()]]);
    }

    public function logo(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);   // SVG deliberately excluded (script injection)

        if ($old = Setting::get('app.logo_path')) {
            Storage::disk('public')->delete($old);
        }
        Setting::put('app.logo_path', $request->file('logo')->store('branding', 'public'));

        return response()->json(['data' => self::branding()]);
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($old = Setting::get('app.logo_path')) {
            Storage::disk('public')->delete($old);
        }
        Setting::put('app.logo_path', null);

        return response()->json(['data' => self::branding()]);
    }

    public function favicon(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $request->validate(['favicon' => ['required', 'file', 'mimes:png,ico,webp', 'max:512']]);       // SVG deliberately excluded (script injection)

        if ($old = Setting::get('app.favicon_path')) {
            Storage::disk('public')->delete($old);
        }
        Setting::put('app.favicon_path', $request->file('favicon')->store('branding', 'public'));
        tenant()?->update(['favicon_version' => time()]);                  // the platform's Companies list shows it as the company's icon

        return response()->json(['data' => self::branding()]);
    }

    public function destroyFavicon(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($old = Setting::get('app.favicon_path')) {
            Storage::disk('public')->delete($old);
        }
        Setting::put('app.favicon_path', null);
        tenant()?->update(['favicon_version' => null]);

        return response()->json(['data' => self::branding()]);
    }
}
