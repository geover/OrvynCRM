<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Believes the X-Forwarded-* headers (the visitor's address, https) only from the proxies named in TRUSTED_PROXIES (config/security.php).
 * Believing every proxy would let any visitor fake the address that rate limits and logs use.
 */
class TrustProxies extends Middleware
{
    /** @return array<int, string>|string|null */
    protected function proxies()
    {
        $configured = trim((string) config('security.trusted_proxies'));

        return $configured === '*' ? '*' : (array_values(array_filter(array_map('trim', explode(',', $configured)))) ?: null);
    }
}
