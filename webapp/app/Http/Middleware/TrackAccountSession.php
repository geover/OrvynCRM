<?php

namespace App\Http\Middleware;

use App\Services\Auth\AccountSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps "last seen" of a signed-in person's session up to date (see AccountSessions). */
class TrackAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->hasSession() && $request->session()->has(AccountSessions::KEY)) {
            try {
                app(AccountSessions::class)->touch($request);
            } catch (\Throwable $e) {
                report($e);                                           // a failed note about a visit never breaks the visit
            }
        }

        return $response;
    }
}
