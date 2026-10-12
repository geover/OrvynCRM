<?php

namespace App\Http\Middleware;

use App\Models\AccountUser;
use App\Services\Tenancy\CompanySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The platform area (all companies) is for platform administrators only; they sign in like everybody else. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('identity_id');
        $identity = $id ? AccountUser::whereKey($id)->where('status', 'active')->first() : null;

        if (! $identity) {
            return redirect('/login');
        }
        abort_unless($identity->is_platform_admin, 403);

        $request->attributes->set('platform_identity', $identity);

        // Support mode (a company opened from the platform) must not leak into platform pages: its look, timezone and
        // settings are the company's. Only the companies list used to close it, so every other platform page could
        // render with the company's branding after a company's app details were saved.
        app(CompanySession::class)->leaveCurrentCompany($request);

        return $next($request);
    }
}
