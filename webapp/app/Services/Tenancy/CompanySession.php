<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\AccountSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

/**
 * Signs a person in to ONE company: opens that company's database, finds their profile there, logs the profile in
 * with the normal session guard and remembers the company in the session (and in a cookie, so a "remember me"
 * login can find its company before the session exists).
 */
class CompanySession
{
    public const COOKIE = 'orvyn_company';

    /** Not secret and not tied to a session: just a hint for the chooser (which company to offer first next time). */
    public const LAST_COOKIE = 'orvyn_last_company';

    public function __construct(private TenantProvisioner $provisioner) {}

    /** @return Collection<int, Tenant> the active companies this person belongs to */
    public function companiesFor(AccountUser $identity): Collection
    {
        return $identity->tenants()->where('tenants.status', 'active')->orderBy('tenants.name')->get();
    }

    public function identity(Request $request): ?AccountUser
    {
        $id = $request->session()->get('identity_id');

        return $id ? AccountUser::whereKey($id)->where('status', 'active')->first() : null;
    }

    /**
     * @throws ValidationException when the person may not use that company
     */
    public function enter(Request $request, AccountUser $identity, Tenant $tenant, bool $remember = false): RedirectResponse
    {
        $member = $identity->tenants()->whereKey($tenant->getTenantKey())->exists();
        if (! $tenant->isActive() || (! $member && ! $identity->is_platform_admin)) {
            throw ValidationException::withMessages(['company' => 'You do not have access to that company.']);
        }

        $this->leaveCurrentCompany($request);
        tenancy()->initialize($tenant);

        $profile = User::where('central_user_id', $identity->id)->first();
        if (! $profile && $identity->is_platform_admin) {
            $profile = $this->provisioner->addSupportProfile($tenant, $identity);
        }
        if (! $profile || ! $profile->isActive()) {
            tenancy()->end();
            throw ValidationException::withMessages(['company' => 'Your access to that company has been suspended.']);
        }

        Auth::guard('web')->login($profile, $remember);
        $identity->is_platform_admin || app(CompanyActivity::class)->touch($tenant);          // a person signed in (a platform visit is not use)
        $request->session()->put('tenant_id', $tenant->getTenantKey());
        $identity->is_platform_admin || app(AccountSessions::class)->enterCompany($request, (string) $tenant->getTenantKey());
        Cookie::queue(self::COOKIE, $tenant->getTenantKey(), $remember ? 60 * 24 * 30 : 0);
        Cookie::queue(self::LAST_COOKIE, $tenant->getTenantKey(), 60 * 24 * 365);

        return redirect()->intended('/');
    }

    /** Close the company the session is currently in (switching company, or going back to the platform area). */
    public function leaveCurrentCompany(Request $request): void
    {
        if (tenancy()->initialized) {
            Auth::guard('web')->logout();           // while the company is still open: the guard rotates the profile's remember token
            tenancy()->end();
        }
        $request->session()->forget('tenant_id');
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
