<?php

namespace App\Support;

use App\Support\Tenancy\Company;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** A short, plain e-mail in the platform's name: a title, a few lines and an optional button. A mail problem never throws. */
class NoticeMail
{
    /**
     * @param  list<string>  $lines
     * @param  array{0: string, 1: string}|null  $button  [label, address]
     */
    public static function send(string $to, string $subject, array $lines, ?array $button = null): void
    {
        try {
            Mail::send(['html' => 'mail.notice', 'text' => 'mail.notice-text'], ['title' => $subject, 'lines' => $lines, 'button' => $button, 'brand' => Company::platformBranding()['name']], fn ($m) => $m->to($to)->subject($subject));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
