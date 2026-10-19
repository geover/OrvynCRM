<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Tenant;
use App\Services\Auth\AccountSessions;
use App\Services\Auth\SignIn;
use App\Services\Auth\SignInSecurity;
use App\Services\Auth\SocialProviders;
use App\Services\Tenancy\CompanySession;
use App\Services\Tenancy\Recaptcha;
use App\Support\LoginThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/** The one sign-in page for every company: credentials are checked centrally, then the person picks (or is given) a company. */
class WebAuthController extends Controller
{
    public function create(SocialProviders $providers, SignInSecurity $security): Response
    {
        return Inertia::render('Auth/Login', ['providers' => $providers->offered(), 'passkeys' => $security->passkeysEnabled()]);
    }

    public function store(Request $request, SignIn $signIn, Recaptcha $recaptcha): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        LoginThrottle::ensureNotLocked($request);

        // bot protection (when the platform has switched it on): a failed check is not a wrong password, so it never counts toward a lockout
        if (! $recaptcha->passes($request, 'login')) {
            return back()->withErrors(['email' => 'We could not confirm that you are a person. Please try again.'])->onlyInput('email');
        }

        $identity = AccountUser::where('email', mb_strtolower(trim($credentials['email'])))->first();
        if (! $identity || ! $identity->isActive() || ! Hash::check($credentials['password'], $identity->password)) {
            LoginThrottle::fail($request);

            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        LoginThrottle::succeed($request);

        return $signIn->proven($request, $identity, $request->boolean('remember'));
    }

    /** Pick one of your companies (several memberships) or switch to another one. */
    public function chooseForm(Request $request, CompanySession $companies): Response|RedirectResponse
    {
        $identity = $companies->identity($request);
        if (! $identity) {
            return redirect('/login');
        }

        // the company used last comes first, the rest alphabetically
        $last = $request->cookie(CompanySession::LAST_COOKIE);
        $mine = $companies->companiesFor($identity)->sortBy(fn (Tenant $t) => [$t->getTenantKey() === $last ? 0 : 1, mb_strtolower($t->name)])->values();

        return Inertia::render('Auth/ChooseCompany', [
            'companies' => $mine->map(fn (Tenant $t) => ['id' => $t->getTenantKey(), 'name' => $t->name, 'slug' => $t->slug])->values(),
            'last' => $mine->contains(fn (Tenant $t) => $t->getTenantKey() === $last) ? $last : null,
            'current' => tenancy()->initialized ? tenant('id') : null,
            'email' => $identity->email,
        ]);
    }

    public function choose(Request $request, CompanySession $companies): RedirectResponse
    {
        $identity = $companies->identity($request) ?? abort(401);
        $tenant = Tenant::findOrFail($request->validate(['company' => ['required', 'string']])['company']);

        return $companies->enter($request, $identity, $tenant, (bool) $request->session()->get('remember', false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        app(AccountSessions::class)->close($request);                       // the sign-in ends here
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(CompanySession::COOKIE));

        return redirect('/login');
    }
}
