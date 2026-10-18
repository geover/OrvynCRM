<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser-side protections every response carries: no guessing of file types, no framing by other sites, a short referrer, and a
 * Content-Security-Policy that only lets the page run its own scripts (plus reCAPTCHA when it is on). A response that sets one of these
 * itself (the sandboxed attachments, the public forms, cleaned SVGs) keeps its own.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('security.csp', 'enforce');
        $csp = in_array($mode, ['enforce', 'report'], true) && ! Vite::isRunningHot();
        $nonce = $csp ? Vite::useCspNonce() : null;       // the inline script in the page and Vite's tags carry it

        $response = $next($request);

        $set = function (string $name, string $value) use ($response): void {
            $response->headers->has($name) || $response->headers->set($name, $value);
        };
        $set('X-Content-Type-Options', 'nosniff');
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(), geolocation=(), payment=(), usb=()');
        if (config('security.hsts') && $request->isSecure() && app()->environment('production')) {
            $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($csp && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set($mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $this->policy((string) $nonce, $request->isSecure()));
        }

        return $response;
    }

    private function policy(string $nonce, bool $secure): string
    {
        $recaptcha = 'https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/';
        $parts = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$recaptcha}",
            "style-src 'self' 'unsafe-inline'",              // Vue and the colour settings write style attributes
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            "font-src 'self' data:",
            "connect-src 'self' https://www.google.com/recaptcha/",
            "frame-src 'self' blob: https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];
        $secure && $parts[] = 'upgrade-insecure-requests';

        return implode('; ', $parts);
    }
}
