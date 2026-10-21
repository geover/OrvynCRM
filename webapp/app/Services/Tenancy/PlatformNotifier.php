<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Who among the platform administrators wants an e-mail about something, and sending it. Each administrator chooses on the
 * platform's Settings page whether to be told about requests, messages and failed automations, and which address to use
 * (default: their own).
 */
class PlatformNotifier
{
    public const REQUESTS = 'requests';

    public const MESSAGES = 'messages';

    public const AUTOMATION_FAILURES = 'automation_failures';

    public const INACTIVE_COMPANIES = 'inactive_companies';

    public const ONBOARDING = 'onboarding';

    /**
     * @param  self::REQUESTS|self::MESSAGES|self::AUTOMATION_FAILURES|self::INACTIVE_COMPANIES|self::ONBOARDING  $topic
     * @return list<string> distinct addresses
     */
    public function recipients(string $topic): array
    {
        $column = match ($topic) {
            self::MESSAGES => 'notify_messages',
            self::AUTOMATION_FAILURES => 'notify_automation_failures',
            self::INACTIVE_COMPANIES => 'notify_inactive_companies',
            self::ONBOARDING => 'notify_onboarding',
            default => 'notify_requests',
        };

        return AccountUser::where('is_platform_admin', true)->where('status', 'active')->where($column, true)->get()
            ->map(fn (AccountUser $a) => trim((string) $a->notify_email) !== '' ? trim($a->notify_email) : $a->email)
            ->unique()->values()->all();
    }

    /**
     * E-mail everybody who wants to hear about `$topic`. The app's own pages (inbox, badges, Requests) are the real
     * notification, so a mail problem never throws: it must not break what triggered it.
     *
     * @param  self::REQUESTS|self::MESSAGES|self::AUTOMATION_FAILURES|self::INACTIVE_COMPANIES  $topic
     * @param  array{0: string, 1: string}|null  $replyTo  [address, name]
     */
    public function mail(string $topic, string $subject, string $text, ?array $replyTo = null): void
    {
        foreach ($this->recipients($topic) as $email) {
            try {
                Mail::raw($text, function ($message) use ($email, $subject, $replyTo) {
                    $message->to($email)->subject($subject);
                    $replyTo && $message->replyTo($replyTo[0], $replyTo[1]);
                });
            } catch (Throwable) {
                // see above
            }
        }
    }
}
