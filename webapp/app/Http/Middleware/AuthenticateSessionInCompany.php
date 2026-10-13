<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum validates the browser session (it looks the signed-in person up) inside its own "stateful" pipeline, right
 * after the session starts and BEFORE the middleware in our route groups run. The person lives in a company database,
 * so that company must be open first; ResolveTenant does it, then Sanctum's check proceeds as normal.
 * Wired in through config/sanctum.php (middleware.authenticate_session).
 */
class AuthenticateSessionInCompany extends AuthenticateSession
{
    public function handle(Request $request, Closure $next): Response
    {
        return app(ResolveTenant::class)->handle($request, fn (Request $r) => parent::handle($r, $next));
    }
}
