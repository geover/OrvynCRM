<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\CompanyActivity;
use App\Services\Tenancy\Plans;
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

        // the free trial is over and no billing details are on file: only the billing page (and signing out) is open until they are given
        if ($user && tenancy()->initialized && ! ($user->is_platform_support ?? false) && ! $this->isBillingPage($request) && app(Plans::class)->isBlocked(tenant())) {
            $message = 'Your free trial has ended. Your administrator needs to enter the billing information to continue.';

            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => $message, 'code' => 'billing_required'], 402)
                : redirect('/billing');
        }

        // a person is using their company: the platform's check for abandoned companies counts it (see CompanyActivity)
        if ($user && ! ($user->is_platform_support ?? false) && $this->isUse($request)) {
            app(CompanyActivity::class)->touch();
        }

        return $next($request);
    }

    /** What stays open after the trial: the billing page and its data, the manual, and the calls every page makes about the person and the company's look. */
    private function isBillingPage(Request $request): bool
    {
        return $request->is('billing', 'billing/*', 'manual', 'branding/*', 'api/v1/billing', 'api/v1/billing/*', 'api/v1/me', 'api/v1/auth/logout', 'api/v1/notifications', 'api/v1/notifications/*', 'api/v1/platform-threads/poll')
            || ($request->is('api/v1/settings') && $request->isMethod('GET'));
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
