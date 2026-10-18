<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    | enforce: the browser blocks what the policy does not allow. report: it only reports (the page works, the browser console
    | lists what would have been blocked: use it to try a change). off: no policy. The policy is skipped while the Vite dev server
    | runs (public/hot), which needs scripts and a socket from another port.
    */
    'csp' => env('SECURITY_CSP', 'enforce'),

    /*
    | Strict-Transport-Security: only sent on https requests in production. Turn it off only if the site must still be reachable over http.
    */
    'hsts' => (bool) env('SECURITY_HSTS', true),

    /*
    | Proxies whose X-Forwarded-* headers (the visitor's address, https) are believed. Empty: none, which is right when the app is reached
    | directly. "*": every proxy, only safe when nothing but your own proxy can reach the app. Or a comma separated list of proxy addresses.
    | A wrong value lets a visitor fake the address that rate limits and logs use.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

];
