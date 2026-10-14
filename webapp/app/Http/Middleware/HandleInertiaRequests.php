<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\SettingsController;
use App\Models\AccountUser;
use App\Models\Announcement;
use App\Models\MessageThread;
use App\Models\SeatRequest;
use App\Models\SubscriptionRequest;
use App\Models\Tenant;
use App\Services\Heartbeat;
use App\Services\Tenancy\Plans;
use App\Support\Tenancy\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Inside the app: the company's look, except that the logo follows the platform's whenever the company has none (so
     * a platform administrator changing the default logo reaches everybody at once, not after the branding cache expires).
     *
     * @return array<string, mixed>
     */
    private function branding(Request $request): array
    {
        if (Company::isPlatformArea($request)) {
            return Company::platformAreaBranding();               // the platform area has a look of its own
        }
        if (! Company::isOpen() || Company::isSignInPage($request)) {
            return Company::platformBranding();
        }

        $brand = Cache::remember('branding', 300, fn () => SettingsController::branding());
        if ($brand['logo_is_default'] ?? true) {
            $brand['logo_url'] = Company::platformBranding()['logo_url'];
            $brand['logo_light_url'] = Company::platformBranding()['logo_light_url'];
        }

        if ($brand['favicon_is_default'] ?? true) {
            $brand['favicon_url'] = Company::platformBranding()['favicon_url'];
        }

        return $brand;
    }

    /**
     * The latest announcements for the sign-in page (cached briefly: this page is public and busy).
     *
     * @return list<array<string, mixed>>
     */
    private function loginUpdates(): array
    {
        return Cache::remember('announcements:login', 60, fn () => Announcement::live()->where('show_on_login', true)->newestFirst()->limit(5)->get()->map->toReader()->values()->all());
    }

    /** @return array{state: string, days_left: int|null, billing_complete: bool, blocked: bool}|null only while a trial runs or has run out without billing details */
    private function trial(): ?array
    {
        $company = tenant();
        if (! $company || $company->trial_ends_at === null) {
            return null;
        }
        $state = app(Plans::class)->state($company);

        return in_array($state['state'], ['trial', 'expired'], true) && ! $state['ok'] ? array_intersect_key($state, array_flip(['state', 'days_left', 'billing_complete', 'payment_required', 'blocked'])) : null;
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $company = Company::current();
        $identityId = $request->hasSession() ? $request->session()->get('identity_id') : null;

        return array_merge(parent::share($request), [
            // The company's own look (name, tagline, colour, logo, timezone). The sign-in, chooser and password pages, and the
            // platform area, always show the platform's look, even while a company is open in the session.
            'app' => $this->branding($request),
            'auth' => ['user' => $user ? [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->role, 'is_admin' => $user->isAdmin(), 'permissions' => $user->permissionTemplate?->permissions ?? [],
            ] : null],
            // Which company is open, and the other companies this person can switch to.
            'company' => $company ? ['id' => $company->getTenantKey(), 'name' => $company->name, 'slug' => $company->slug] : null,
            'companies' => fn () => $identityId && ($identity = AccountUser::find($identityId))
                ? $identity->tenants()->where('tenants.status', 'active')->orderBy('tenants.name')->get(['tenants.id', 'tenants.name', 'tenants.slug'])->map->only(['id', 'name', 'slug'])->values()
                : [],
            // What's new: the sign-in page shows the latest announcements; inside a company only the ids (for the "new" badge).
            'signInUpdates' => fn () => $request->is('login') ? $this->loginUpdates() : [],
            'updates' => fn () => Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request)
                ? ['ids' => Announcement::live()->where('show_in_app', true)->orderByDesc('id')->limit(100)->pluck('id')->all()]
                : null,
            // requests for more users waiting for a platform administrator (the badge in the platform menu)
            // companies the daily check has flagged as abandoned: a badge on the platform's Companies menu until they are dealt with
            'inactiveCompanies' => fn () => Company::isPlatformArea($request) ? Tenant::active()->whereNotNull('inactive_since')->count() : 0,
            // the free trial of the open company: a banner for its administrators while it runs out, and the page they are sent to once it has
            'trial' => fn () => Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request) && $request->user() && tenancy()->initialized
                ? $this->trial() : null,
            // a background process (scheduler or queue worker) that has gone quiet: a banner in the platform area
            'stoppedServices' => fn () => Company::isPlatformArea($request) && $identityId ? app(Heartbeat::class)->problems() : [],
            'seatRequestsPending' => fn () => Company::isPlatformArea($request) ? SeatRequest::pending()->count() + SubscriptionRequest::pending()->count() : 0,
            'platformMessagesUnread' => fn () => Company::isPlatformArea($request) ? MessageThread::unreadForPlatform()->count() : 0,
            // a company administrator: conversations with the platform that have a new reply
            'platformThreadsUnread' => fn () => Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request) && $request->user()?->isAdmin() && tenancy()->initialized
                ? MessageThread::unreadForTenant((string) tenant('id'))->count() : 0,
            // who is signed in at the platform level (the platform area has no company profile to show)
            'identity' => fn () => $identityId && ($who = AccountUser::find($identityId)) ? ['name' => $who->name, 'email' => $who->email] : null,
            'platform' => fn () => $identityId && AccountUser::whereKey($identityId)->where('is_platform_admin', true)->exists(),
            'flash' => ['success' => fn () => $request->session()->get('success'), 'warning' => fn () => $request->session()->get('warning')],
        ]);
    }
}
