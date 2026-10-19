<?php

/*
| Fields a form may send without the server having a rule for them (patterns, "*" stands for any key or list position). Everything else that is
| sent and not validated is reported by App\Support\IgnoredInput: in the X-Ignored-Input response header and in the log (never in production).
*/
return [
    'allowed' => [
        'password_confirmation',
        'remember',
        'g-recaptcha-response',
        'cf-turnstile-response',
        'h-captcha-response',
        'recaptcha_token',
    ],
];
