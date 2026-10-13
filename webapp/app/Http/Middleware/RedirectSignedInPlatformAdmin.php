<?php

namespace App\Http\Middleware;

use App\Models\AccountUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sign-in and sign-up pages are for people who are signed out. `guest` only knows about a company user, so a platform
 * administrator (signed in centrally, with no company open) would still see them: send them back to the platform instead.
 */
class RedirectSignedInPlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->hasSession() ? $request->session()->get('identity_id') : null;

        if ($id && AccountUser::whereKey($id)->where('status', 'active')->where('is_platform_admin', true)->exists()) {
            return redirect('/platform');
        }

        return $next($request);
    }
}
