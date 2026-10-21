<?php

namespace App\Services\Onboarding;

use App\Models\AccountUser;
use App\Models\PlatformSetting;

/** The platform's choices for the onboarding series: how often to write, when a company counts as stuck and how the platform is told (Platform > Onboarding > Settings). */
class OnboardingSettings
{
    public const DEFAULTS = [
        'enabled' => true,          // the whole series
        'gap_days' => 3,            // at least this many days between two e-mails to a company
        'max_per_week' => 2,        // and never more than this many in any 7 days
        'stalled_days' => 7,        // no new step for this long: it needs a hand
        'trial_help_days' => 7,     // a trial that ends within this many days with little done: it needs a hand
        'inactive_days' => 7,       // nobody signed in for this long: it needs a hand
        'alert_badge' => true,      // a badge in the platform menu
        'alert_digest' => true,     // a daily summary e-mail
        'reply_to' => '',           // where replies to the e-mails go (empty: the first platform administrator)
    ];

    private const RANGES = ['gap_days' => [1, 14], 'max_per_week' => [1, 7], 'stalled_days' => [2, 60], 'trial_help_days' => [1, 30], 'inactive_days' => [2, 60]];

    public function int(string $key): int
    {
        [$min, $max] = self::RANGES[$key];
        $v = (int) PlatformSetting::get("onboarding.$key", (string) self::DEFAULTS[$key]);

        return $v >= $min && $v <= $max ? $v : self::DEFAULTS[$key];
    }

    public function on(string $key): bool
    {
        return PlatformSetting::get("onboarding.$key", self::DEFAULTS[$key] ? '1' : '0') !== '0';
    }

    /** @return array<string, int|bool> */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = is_bool($default) ? $this->on($key) : (is_string($default) ? (string) PlatformSetting::get("onboarding.$key", $default) : $this->int($key));
        }

        return $out;
    }

    /**
     * Where a reply to an onboarding e-mail goes: the address the platform chose, else the first platform administrator (their notification address when they have one).
     *
     * @return array{0: string, 1: string}|null [address, name]
     */
    public function replyTo(): ?array
    {
        $chosen = trim((string) PlatformSetting::get('onboarding.reply_to', ''));
        if ($chosen !== '' && filter_var($chosen, FILTER_VALIDATE_EMAIL)) {
            return [$chosen, ''];
        }
        $admin = AccountUser::where('is_platform_admin', true)->where('status', 'active')->orderBy('id')->first();
        if (! $admin) {
            return null;
        }

        return [trim((string) $admin->notify_email) !== '' ? trim($admin->notify_email) : $admin->email, $admin->name];
    }

    /** @param  array<string, mixed>  $d */
    public function save(array $d): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $d)) {
                continue;
            }
            if (is_bool($default)) {
                PlatformSetting::put("onboarding.$key", ! empty($d[$key]) ? '1' : '0');
            } elseif (is_string($default)) {
                PlatformSetting::put("onboarding.$key", trim((string) $d[$key]));
            } else {
                [$min, $max] = self::RANGES[$key];
                PlatformSetting::put("onboarding.$key", (string) min($max, max($min, (int) $d[$key])));
            }
        }
    }
}
