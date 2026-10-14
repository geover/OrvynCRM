<?php

namespace App\Services;

use App\Services\Tenancy\PlatformNotifier;
use Illuminate\Support\Facades\Cache;

/**
 * Knows whether the scheduler (schedule:work / cron) and the queue worker (queue:work) are alive. The scheduler writes a beat
 * every minute and hands the worker a tiny job that writes its own, so a silent process shows up as an old beat. Whoever opens
 * the platform area runs the check: a banner shows, and the platform administrators are e-mailed once per outage.
 */
class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const WORKER = 'worker';

    /** Minutes of silence after which a process counts as stopped. */
    private const STALE_AFTER = [self::SCHEDULER => 3, self::WORKER => 5];

    private const LABEL = [self::SCHEDULER => 'The scheduler (php artisan schedule:work, or the cron entry)', self::WORKER => 'The queue worker (php artisan queue:work)'];

    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function beat(string $name): void
    {
        Cache::forever("heartbeat.{$name}", now()->getTimestamp());
    }

    /**
     * What is running. The worker is only judged while the scheduler is alive, because its beat is a job the scheduler hands out.
     *
     * @return array<string, array{running: bool|null, seen: string|null}>
     */
    public function status(): array
    {
        $out = [];
        foreach (self::STALE_AFTER as $name => $minutes) {
            $at = Cache::get("heartbeat.{$name}");
            $out[$name] = ['running' => $at !== null && $at >= now()->subMinutes($minutes)->getTimestamp(), 'seen' => $at ? now()->setTimestamp($at)->diffForHumans() : null];
        }
        if (! $out[self::SCHEDULER]['running']) {
            $out[self::WORKER]['running'] = $out[self::WORKER]['seen'] === null ? false : null;   // unknown while nothing hands it a beat
        }

        return $out;
    }

    /**
     * The check the platform area runs: names what is stopped for the banner and e-mails the platform administrators once per outage
     * (only for a process that was seen alive before, so a fresh install does not mail).
     *
     * @return list<string> what is stopped, ready to show
     */
    public function problems(): array
    {
        $problems = [];
        foreach ($this->status() as $name => $s) {
            $key = "heartbeat.alerted.{$name}";
            if ($s['running'] !== false) {
                $s['running'] && Cache::forget($key);

                continue;
            }
            $problems[] = self::LABEL[$name].' is not running'.($s['seen'] ? ' (last seen '.$s['seen'].')' : ' (never seen)').'.';
            if ($s['seen'] && Cache::add($key, true, now()->addDay())) {
                $this->notifier->mail(PlatformNotifier::REQUESTS, 'A background process has stopped', self::LABEL[$name]." is not running (last seen {$s['seen']}).\n\nWhile it is stopped, texts and e-mails are not sent, automations do not run and usage is not measured. Start it again on the server.");
            }
        }

        return $problems;
    }
}
