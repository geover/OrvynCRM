<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\CompanyActivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            $user->currentAccessToken()?->delete();
            auth()->guard('web')->logout();
            abort(403, 'Your account is suspended.');
        }

        // a person is using their company: the platform's check for abandoned companies counts it (see CompanyActivity)
        if ($user && ! ($user->is_platform_support ?? false) && $this->isUse($request)) {
            app(CompanyActivity::class)->touch();
        }

        return $next($request);
    }

    /** A page opened or something changed. Background polling (JSON reads) is not use: a forgotten open tab must not keep a company alive. */
    private function isUse(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return true;
        }

        return $request->header('X-Inertia') !== null || ($request->acceptsHtml() && ! $request->expectsJson());
    }
}
