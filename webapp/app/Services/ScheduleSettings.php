<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Throwable;

/**
 * How often the platform's own jobs run. Only a few can be changed, and only to a fixed list of choices, so a saved value can never
 * produce a broken schedule. The scheduler reads this each time it starts (schedule:work starts it every minute), so a change applies
 * at once and nothing needs restarting. If the setting cannot be read (no database yet, during a migration), the default is used.
 */
class ScheduleSettings
{
    /** What the choices mean as a cron expression: {m} is the minute and {h} the hour of the chosen time. */
    private const CRON = [
        'every_15_minutes' => '*/15 * * * *',
        'every_30_minutes' => '*/30 * * * *',
        'hourly' => '{m} * * * *',
        'every_2_hours' => '{m} */2 * * *',
        'every_6_hours' => '{m} */6 * * *',
        'daily' => '{m} {h} * * *',
    ];

    public const LABELS = [
        'every_15_minutes' => 'Every 15 minutes', 'every_30_minutes' => 'Every 30 minutes', 'hourly' => 'Every hour',
        'every_2_hours' => 'Every 2 hours', 'every_6_hours' => 'Every 6 hours', 'daily' => 'Once a day',
    ];

    /** The jobs that can be changed: what they do, the command, their default and the choices offered. */
    public const JOBS = [
        'usage' => ['command' => 'orvyn:track-usage', 'name' => 'companies:usage', 'default' => ['frequency' => 'hourly', 'at' => '00:20'], 'choices' => ['every_15_minutes', 'every_30_minutes', 'hourly', 'every_2_hours', 'every_6_hours', 'daily']],
        'trial-reminders' => ['command' => 'orvyn:trial-reminders', 'name' => 'companies:trial-reminders', 'default' => ['frequency' => 'daily', 'at' => '04:30'], 'choices' => ['every_6_hours', 'daily']],
        'inactivity' => ['command' => 'orvyn:scan-inactive', 'name' => 'companies:inactivity', 'default' => ['frequency' => 'daily', 'at' => '04:00'], 'choices' => ['every_6_hours', 'daily']],
    ];

    /** @return array{frequency: string, at: string} */
    public function get(string $job): array
    {
        $default = self::JOBS[$job]['default'];
        try {
            $frequency = PlatformSetting::get("schedule.{$job}.frequency") ?? $default['frequency'];
            $at = PlatformSetting::get("schedule.{$job}.at") ?? $default['at'];
        } catch (Throwable) {
            return $default;
        }

        return in_array($frequency, self::JOBS[$job]['choices'], true) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) ? ['frequency' => $frequency, 'at' => $at] : $default;
    }

    /** The cron expression the scheduler uses for a job. */
    public function cron(string $job): string
    {
        $s = $this->get($job);
        [$h, $m] = array_map('intval', explode(':', $s['at']));

        return str_replace(['{m}', '{h}'], [(string) $m, (string) $h], self::CRON[$s['frequency']]);
    }

    public function save(string $job, string $frequency, string $at): void
    {
        PlatformSetting::put("schedule.{$job}.frequency", $frequency);
        PlatformSetting::put("schedule.{$job}.at", $at);
    }

    public function reset(string $job): void
    {
        PlatformSetting::put("schedule.{$job}.frequency", null);
        PlatformSetting::put("schedule.{$job}.at", null);
    }

    public function isDefault(string $job): bool
    {
        return $this->get($job) === self::JOBS[$job]['default'];
    }
}
