<?php

namespace App\Http\Middleware;

use App\Models\AccountUser;
use App\Services\Auth\SignInSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the platform requires two-step verification for someone's role, they can do nothing but set it up until they have: every other page
 * sends them to their security page, and the API refuses with a message the app understands.
 */
class RequireTwoFactor
{
    /** Always reachable: the setup itself, signing out, and the public pictures. */
    private const OPEN = ['security', 'platform/security', 'account/security/*', 'logout', 'brand/*', 'up'];

    public function __construct(private readonly SignInSecurity $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->security->policy() === 'optional') {
            return $next($request);                     // nothing is required of anybody: no lookups on every request
        }

        $companyOpen = tenancy()->initialized;
        $profile = $companyOpen ? Auth::guard('web')->user() : null;
        // the session names the identity; an API call carried by the session guard alone is told by the profile it is signed in as
        $id = ($request->hasSession() ? $request->session()->get('identity_id') : null) ?? $profile?->central_user_id;
        $identity = $id ? AccountUser::whereKey($id)->first() : null;
        if (! $identity || $request->is(...self::OPEN)) {
            return $next($request);
        }

        $companyAdmin = $companyOpen ? (bool) $profile?->isAdmin() : null;
        if ($this->security->satisfied($identity, $companyAdmin)) {
            return $next($request);
        }
        // Somebody who belongs to companies and has none open yet (the chooser) is judged once they are inside one
        if ($companyAdmin === null && ! $identity->is_platform_admin) {
            return $next($request);
        }

        $message = 'Your administrator requires two-step verification. Set it up to continue.';
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message, 'two_factor_required' => true], 403);
        }

        return redirect($companyOpen ? '/security' : '/platform/security')->with('warning', $message);
    }
}
