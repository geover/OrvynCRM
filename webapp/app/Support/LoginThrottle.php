<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brute-force protection for credential endpoints: only FAILED attempts count (5 per minute per email + IP) and a
 * successful sign-in clears the counter. A plain "throttle" middleware also counts successes, which locks out
 * legitimate users who sign in from several devices/sessions.
 */
class LoginThrottle
{
    public const MAX_FAILURES = 5;

    public static function key(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }

    /** Throws a validation error (HTTP 422) when the caller is currently locked out. */
    public static function ensureNotLocked(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($key = self::key($request), self::MAX_FAILURES)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages(['email' => "Too many failed sign-in attempts. Try again in {$seconds} seconds."]);
        }
    }

    public static function fail(Request $request): void
    {
        RateLimiter::hit(self::key($request), 60);
    }

    public static function succeed(Request $request): void
    {
        RateLimiter::clear(self::key($request));
    }
}
