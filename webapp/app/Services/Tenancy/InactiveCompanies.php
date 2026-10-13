<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\NoticeMail;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Companies nobody has used for a while. A daily check flags each company that has had no activity for the number of days the
 * platform chose (default 30), tells that company's administrators and the platform, and the platform then decides, company by
 * company: keep it, suspend it or close it. Nothing is ever suspended, closed or deleted automatically.
 */
class InactiveCompanies
{
    public const DEFAULT_DAYS = 30;

    public function __construct(private PlatformNotifier $notifier) {}

    // ---- the settings (Platform > Settings > Inactive companies) -------------------------------------------------------------------

    /** On unless the platform switched it off. */
    public function enabled(): bool
    {
        return PlatformSetting::get('inactivity.enabled') !== '0';
    }

    public function days(): int
    {
        $days = (int) PlatformSetting::get('inactivity.days', self::DEFAULT_DAYS);

        return $days >= 7 && $days <= 365 ? $days : self::DEFAULT_DAYS;
    }

    /** Whether a company's own administrators are told when it is flagged (the platform is always told, per administrator's choice). */
    public function tellsCompanyAdmins(): bool
    {
        return PlatformSetting::get('inactivity.notify_admins') !== '0';
    }

    /** @param  array{enabled?: bool, days?: int, notify_admins?: bool}  $d */
    public function save(array $d): void
    {
        PlatformSetting::put('inactivity.enabled', ! empty($d['enabled']) ? '1' : '0');
        PlatformSetting::put('inactivity.days', (string) min(365, max(7, (int) ($d['days'] ?? self::DEFAULT_DAYS))));
        PlatformSetting::put('inactivity.notify_admins', ! empty($d['notify_admins']) ? '1' : '0');
    }

    // ---- finding them ---------------------------------------------------------------------------------------------------------------

    public function cutoff(): CarbonInterface
    {
        return now()->subDays($this->days());
    }

    /** Active companies with no activity since the cutoff (a company never used counts from the day it was created). */
    public function candidates(): Builder
    {
        return Tenant::active()->whereRaw('COALESCE(last_active_at, created_at) < ?', [$this->cutoff()]);
    }

    /** Whole days since the company was last used. */
    public function daysIdle(Tenant $tenant): int
    {
        return (int) ($tenant->last_active_at ?? $tenant->created_at)->diffInDays(now());
    }

    /**
     * The daily check. Flags the companies that have just become inactive (and tells their administrators and the platform) and
     * clears the flag of those that were used again. Safe to run as often as you like: a company is flagged and announced once.
     *
     * @return array{flagged: int, cleared: int}
     */
    public function scan(): array
    {
        if (! $this->enabled()) {
            return ['flagged' => 0, 'cleared' => 0];
        }

        $flagged = $this->candidates()->whereNull('inactive_since')->get();
        foreach ($flagged as $tenant) {
            $tenant->update(['inactive_since' => now()]);
            $idle = $this->daysIdle($tenant);
            $this->tellCompanyAdmins($tenant, [
                "Nobody has used {$tenant->name} for {$idle} days.",
                'If you still want it, just sign in and carry on: this notice then goes away. A workspace that stays unused may be suspended or closed.',
            ], "We have not seen {$tenant->name} in {$idle} days", true);
        }
        $cleared = Tenant::whereNotNull('inactive_since')->whereRaw('COALESCE(last_active_at, created_at) >= ?', [$this->cutoff()])->update(['inactive_since' => null]);

        if ($flagged->isNotEmpty()) {
            $names = $flagged->map(fn (Tenant $t) => '- '.$t->name.' ('.$this->daysIdle($t).' days)')->implode("\n");
            $this->notifier->mail(PlatformNotifier::INACTIVE_COMPANIES, $flagged->count().' '.($flagged->count() === 1 ? 'company has' : 'companies have')." had no activity for {$this->days()} days", "These companies look abandoned:\n\n{$names}\n\nReview them under Platform, Companies (Inactive) and keep, suspend or close each one:\n".url('/platform?show=inactive'));
        }

        return ['flagged' => $flagged->count(), 'cleared' => $cleared];
    }

    // ---- what the platform can do about one -------------------------------------------------------------------------------------

    /** Not abandoned after all: the clock starts again. */
    public function keep(Tenant $tenant): void
    {
        $tenant->update(['last_active_at' => now(), 'inactive_since' => null]);
    }

    /** Nobody in it can sign in or use the API until it is activated again. Its data is kept. */
    public function suspend(Tenant $tenant, ?string $note = null, bool $tell = true): void
    {
        $this->leave($tenant, 'suspended', $note);
        $tell && $this->tellCompanyAdmins($tenant, ["{$tenant->name} has been suspended because it was not used for a while.", ...($note ? [trim($note)] : []), 'Nobody can sign in until it is activated again. Get in touch if you would like to use it.'], "{$tenant->name} has been suspended");
    }

    /** Closed for good as far as the people in it are concerned. The platform can still reopen it (Activate) while its data exists. */
    public function close(Tenant $tenant, ?string $note = null, bool $tell = true): void
    {
        $this->leave($tenant, 'closed', $note);
        $tell && $this->tellCompanyAdmins($tenant, ["{$tenant->name} has been closed because it was not used for a while.", ...($note ? [trim($note)] : []), 'Get in touch if you would like to use it again.'], "{$tenant->name} has been closed");
    }

    private function leave(Tenant $tenant, string $status, ?string $note): void
    {
        $tenant->update(['status' => $status, 'status_note' => $note ? mb_substr(trim($note), 0, 255) : null, 'inactive_since' => null, 'closed_at' => $status === 'closed' ? now() : null]);
    }

    /**
     * An in-app notice (when `$inApp`) and an e-mail for each administrator of the company.
     *
     * @param  list<string>  $lines
     */
    private function tellCompanyAdmins(Tenant $tenant, array $lines, string $subject, bool $inApp = false): void
    {
        if ($inApp && ! $this->tellsCompanyAdmins()) {
            return;
        }

        $admins = $tenant->run(function () use ($subject, $lines, $inApp) {
            $admins = User::where('role', 'admin')->where('status', 'active')->where('is_platform_support', false)->get(['id', 'name', 'email']);
            $inApp && $admins->each(fn (User $a) => DB::table('notifications_feed')->insert([
                'user_id' => $a->id, 'title' => $subject, 'body' => $lines[1] ?? $lines[0], 'url' => '/', 'created_at' => now(), 'updated_at' => now(),
            ]));

            return $admins;
        });

        foreach ($admins as $admin) {
            NoticeMail::send($admin->email, $subject, ["Hi {$admin->name},", ...$lines], $inApp ? ['Open '.$tenant->name, url('/login')] : null);
        }
    }
}
