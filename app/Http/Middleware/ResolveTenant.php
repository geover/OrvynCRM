<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Services\Tenancy\CompanySession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the right company database for the request, before authentication runs:
 *   - an API bearer token belongs to exactly one company;
 *   - a browser session remembers the company the person chose at sign-in (or the platform admin switched to);
 *   - a "remember me" visit has no session yet, only the company cookie, which is enough to find the company in
 *     which the remember-me token can be checked.
 * No company (the login page, the company chooser, the platform area) leaves the central database active.
 */
class ResolveTenant
{
    /** Container flag: a command that drives the kernel itself (bench:run) opened its company and keeps it open. */
    public const KEEP_OPEN = 'orvyn.keep_company_open';

    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized) {
            $tenant = $this->tenantFor($request);

            if ($tenant) {
                if (! $tenant->isActive()) {
                    // The session still names someone in this company, and saving the session looks that person up, so the
                    // company stays open (for this one response) while we refuse. Answered, not thrown: an error page
                    // would render with the signed-in user too.
                    tenancy()->initialize($tenant);

                    return $this->suspended($request);
                }
                tenancy()->initialize($tenant);
            } elseif ($request->hasSession() && ! config('app.single_database')) {
                $this->dropStaleSignIn($request);
            }
        }

        return $next($request);
    }

    private function suspended(Request $request): Response
    {
        $message = 'This company account is suspended.';
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 403);
        }

        // a browser: the session is cut off and the person lands on the sign-in page, which explains it on the next attempt
        $request->hasSession() && $this->dropStaleSignIn($request);

        return redirect('/login');
    }

    /** Leave no company open behind us: workers and long-lived processes serve the next request too. */
    public function terminate(Request $request, Response $response): void
    {
        // a command that drives the kernel itself (bench:run) opens its own company and says so; every other company is
        // closed here, whoever opened it (this middleware, the session guard, or a controller choosing a company)
        if (tenancy()->initialized && ! app()->bound(self::KEEP_OPEN)) {
            tenancy()->end();
        }
    }

    private function tenantFor(Request $request): ?Tenant
    {
        if ($plain = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($plain);

            return $token ? Tenant::find($token->tenant_id) : null;
        }

        if ($request->hasSession() && ($id = $request->session()->get('tenant_id'))) {
            return Tenant::find($id);
        }

        // No session company: it expired and this is a remember-me visit. Only then does the company cookie matter: it is
        // encrypted, so it can only name a company this browser really signed in to, and it merely says WHERE to check
        // the remember-me token. Without a remember-me cookie it is ignored, or a request that was still in flight when
        // the person signed out would plant the company in their next session.
        if ($request->hasSession() && $request->cookies->has(Auth::guard('web')->getRecallerName())
            && ($id = $request->cookie(CompanySession::COOKIE)) && is_string($id)) {
            $tenant = Tenant::find($id);
            $tenant && $request->session()->put('tenant_id', $tenant->getTenantKey());

            return $tenant;
        }

        return null;
    }

    /**
     * No company is open, so nothing in this request may look up a company user. A session or a remember-me cookie can
     * still carry a sign-in from before (the company was deleted, the company cookie is gone): forget it instead of
     * letting the session guard query the central database, where company users do not exist.
     */
    private function dropStaleSignIn(Request $request): void
    {
        $request->session()->forget([Auth::guard('web')->getName(), 'tenant_id']);

        // The same for a "remember me" cookie: the guard would look its user up in the central database, which has no
        // company users (a cookie left from before, or one whose company cookie is gone). Ignore it now and have the
        // browser delete it.
        $recaller = Auth::guard('web')->getRecallerName();
        if ($request->cookies->has($recaller)) {
            $request->cookies->remove($recaller);
            Cookie::queue(Cookie::forget($recaller));
        }
    }
}
