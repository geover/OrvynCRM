<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Support\NoticeMail;
use Illuminate\Support\Facades\DB;

/**
 * Tells a company's administrators that its free trial is running out: 14 days, 7 days and 1 day before it ends, and once
 * when it has ended. Only for companies that have not given their billing details yet. Each notice is sent once (the
 * company remembers the last one in `trial_notice_stage`), so the daily run can be repeated safely.
 */
class TrialReminders
{
    /** days left => stage; the stage after the end is EXPIRED */
    private const STAGES = [14 => 1, 7 => 2, 1 => 3];

    private const EXPIRED = 4;

    public function __construct(private readonly Plans $plans, private readonly PlatformNotifier $notifier) {}

    /** @return int how many companies were told something */
    public function run(): int
    {
        $told = 0;
        $trials = Tenant::active()->whereNotNull('trial_ends_at')->where('trial_notice_stage', '<', self::EXPIRED)->get();

        foreach ($trials as $tenant) {
            if ($this->plans->state($tenant)['ok']) {
                continue;
            }
            $stage = $this->stageFor($tenant);
            if ($stage > (int) $tenant->trial_notice_stage) {
                $tenant->update(['trial_notice_stage' => $stage]);
                $this->tell($tenant, $stage);
                $told++;
            }
        }

        return $told;
    }

    /** The newest stage the company has reached (0: nothing to say yet). */
    private function stageFor(Tenant $tenant): int
    {
        $ends = $tenant->trial_ends_at;
        if ($ends->isPast()) {
            return self::EXPIRED;
        }
        $left = (int) ceil(now()->diffInHours($ends, false) / 24);
        $stage = 0;
        foreach (self::STAGES as $days => $s) {
            $left <= $days && $stage = max($stage, $s);
        }

        return $stage;
    }

    private function tell(Tenant $tenant, int $stage): void
    {
        $name = $tenant->name;
        if ($stage === self::EXPIRED) {
            $subject = "The free trial of {$name} has ended";
            $lines = ['The free trial has ended.', 'Please enter your billing information to carry on: until then only the billing page is open. Nothing has been deleted.'];
        } else {
            $left = (int) ceil(now()->diffInHours($tenant->trial_ends_at, false) / 24);
            $subject = "The free trial of {$name} ends in {$left} ".($left === 1 ? 'day' : 'days');
            $lines = ["The free trial ends on {$tenant->trial_ends_at->format('F j, Y')}.", 'Enter your billing information any time before then, so nothing is interrupted.'];
        }

        $admins = $tenant->run(function () use ($subject, $lines) {
            $admins = User::where('role', 'admin')->where('status', 'active')->where('is_platform_support', false)->get(['id', 'name', 'email']);
            $admins->each(fn (User $a) => DB::table('notifications_feed')->insert([
                'user_id' => $a->id, 'title' => $subject, 'body' => $lines[1], 'url' => '/billing', 'created_at' => now(), 'updated_at' => now(),
            ]));

            return $admins;
        });
        foreach ($admins as $admin) {
            NoticeMail::send($admin->email, $subject, ["Hi {$admin->name},", ...$lines], ['Open billing', url('/billing')]);
        }

        if ($stage === self::EXPIRED) {
            $this->notifier->mail(PlatformNotifier::REQUESTS, "Trial ended: {$name}", "The free trial of {$name} has ended and it has not entered billing information yet.\n\n".url('/platform/companies/'.$tenant->getTenantKey()));
        }
    }
}
