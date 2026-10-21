<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\SettingsController;
use App\Models\AccountUser;
use App\Models\Announcement;
use App\Models\CompanyManualInvoice;
use App\Models\CompanyOnboarding;
use App\Models\CompanySubscription;
use App\Models\CustomTab;
use App\Models\MessageThread;
use App\Models\SeatRequest;
use App\Models\SubscriptionRequest;
use App\Models\SupportRequest;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Services\Heartbeat;
use App\Services\Onboarding\OnboardingSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\UserProfiles;
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
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar_url' => $user->is_platform_support ? app(UserProfiles::class)->supportUrl($user, '/files/users') : app(UserProfiles::class)->url($user, '/files/users'),
                'role' => $user->role, 'is_admin' => $user->isAdmin(), 'is_platform_support' => (bool) $user->is_platform_support, 'permissions' => $user->permissionTemplate?->permissions ?? [],
            ] : null],
            // The pages an administrator added to the main left-hand menu, for the people they are meant for.
            'sidebarTabs' => fn () => $user && Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request) && tenancy()->initialized
                ? CustomTab::where('placement', 'sidebar')->orderBy('position')->get()->filter(fn ($t) => $t->isFor($user))->map(fn ($t) => $t->only(['id', 'title', 'icon']))->values() : [],
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
            // the last card payment failed: a banner for the company's administrators until it goes through
            'paymentFailed' => fn () => $user?->isAdmin() && Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request) && tenancy()->initialized
                ? CompanySubscription::where('tenant_id', tenant('id'))->whereIn('status', ['past_due', 'unpaid'])->exists() : false,
            // a background process (scheduler or queue worker) that has gone quiet: a banner in the platform area
            'stoppedServices' => fn () => Company::isPlatformArea($request) && $identityId ? app(Heartbeat::class)->problems() : [],
            // companies whose payment failed (the badge on the platform's Account standing menu)
            'paymentsFailing' => fn () => Company::isPlatformArea($request) ? CompanySubscription::whereIn('status', ['past_due', 'unpaid'])->count() + CompanyManualInvoice::unpaid()->where('due_on', '<', now()->toDateString())->distinct()->count('tenant_id') : 0,
            // new companies that need a hand getting started (the badge on the platform's Onboarding menu; the platform can switch it off)
            'onboardingHelp' => fn () => Company::isPlatformArea($request) && app(OnboardingSettings::class)->on('alert_badge')
                ? CompanyOnboarding::where('needs_help', true)->where(fn ($q) => $q->whereNull('help_snoozed_until')->orWhere('help_snoozed_until', '<', now()))->count() : 0,
            // support tickets: the platform's badge (the company wrote and nobody looked yet) and a company administrator's (the platform answered and they have not read it)
            'ticketsNeedingPlatform' => fn () => Company::isPlatformArea($request) ? Ticket::needingPlatform()->count() : 0,
            'ticketsUnread' => fn () => Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request) && $request->user()?->isAdmin() && ! $request->user()?->is_platform_support && tenancy()->initialized
                ? Ticket::forTenant((string) tenant('id'))->where('unread_tenant', true)->count() : 0,
            'seatRequestsPending' => fn () => Company::isPlatformArea($request) ? SeatRequest::pending()->count() + SubscriptionRequest::pending()->count() : 0,
            'platformMessagesUnread' => fn () => Company::isPlatformArea($request) ? MessageThread::unreadForPlatform()->count() : 0,
            // a company administrator: conversations with the platform that have a new reply
            'platformThreadsUnread' => fn () => Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request) && $request->user()?->isAdmin() && tenancy()->initialized
                ? MessageThread::unreadForTenant((string) tenant('id'))->count() + SupportRequest::where('unread_admin', true)->count() : 0,
            // any user: answers to their Contact support messages they have not read
            'supportUnread' => fn () => Company::isOpen() && ! Company::isSignInPage($request) && ! Company::isPlatformArea($request) && $request->user() && tenancy()->initialized
                ? SupportRequest::where('user_id', $request->user()->id)->where('unread_user', true)->count() : 0,
            // who is signed in at the platform level (the platform area has no company profile to show)
            'identity' => fn () => $identityId && ($who = AccountUser::find($identityId)) ? ['name' => $who->name, 'email' => $who->email, 'avatar_url' => $who->avatarUrl()] : null,
            'platform' => fn () => $identityId && AccountUser::whereKey($identityId)->where('is_platform_admin', true)->exists(),
            'flash' => ['success' => fn () => $request->session()->get('success'), 'warning' => fn () => $request->session()->get('warning')],
        ]);
    }
}
