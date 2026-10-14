<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
use App\Models\Tenant;
use App\Support\NoticeMail;

/**
 * Tells people about a change to the licence tiers, when the platform administrator who made it chose to: the other platform administrators
 * (an e-mail each) and the administrators of the companies that are on a tier that changed (a bell notice and an e-mail each).
 */
class TierChangeNotices
{
    public function __construct(private readonly Plans $plans, private readonly CompanyAdminNotices $notices) {}

    /**
     * @param  list<array{tier: int, name: string, was: string, changes: list<array{label: string, from: string, to: string}>}>  $diff  see Plans::diff
     * @return array{platform: int, companies: int} how many platform administrators and companies were told
     */
    public function send(array $diff, bool $toPlatform, bool $toCompanies, ?string $note, AccountUser $by): array
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $sent = ['platform' => 0, 'companies' => 0];
        if (! $diff) {
            return $sent;
        }

        if ($toPlatform) {
            $sent['platform'] = $this->tellPlatformAdministrators($diff, $note, $by);
        }
        if ($toCompanies) {
            $sent['companies'] = $this->tellCompanies($diff, $note);
        }

        return $sent;
    }

    /** @param  list<array<string, mixed>>  $diff */
    private function tellPlatformAdministrators(array $diff, ?string $note, AccountUser $by): int
    {
        $lines = ["{$by->name} changed the licence tiers.", ...array_map(fn (array $t) => $this->describe($t), $diff)];
        $n = Tenant::active()->whereIn('tier', array_column($diff, 'tier'))->count();
        $lines[] = $n === 1 ? '1 active company is on a tier that changed and follows the new limits at once.' : "{$n} active companies are on the tiers that changed and follow the new limits at once.";
        $note && $lines[] = "Note from {$by->name}: {$note}";

        $sent = 0;
        foreach (AccountUser::where('is_platform_admin', true)->where('status', 'active')->where('id', '!=', $by->id)->get() as $admin) {
            NoticeMail::send(trim((string) $admin->notify_email) !== '' ? trim($admin->notify_email) : $admin->email, 'Licence tiers changed by '.$by->name, ["Hi {$admin->name},", ...$lines], ['Open Plans & trial', url('/platform/settings/plans')]);
            $sent++;
        }

        return $sent;
    }

    /** @param  list<array<string, mixed>>  $diff */
    private function tellCompanies(array $diff, ?string $note): int
    {
        $byTier = collect($diff)->keyBy('tier');
        $n = 0;
        foreach (Tenant::active()->whereIn('tier', $byTier->keys()->all())->get() as $tenant) {
            $t = $byTier[(int) $tenant->tier];
            $lines = ["The platform changed what {$t['name']} includes.", ...array_map(fn (array $c) => "{$c['label']}: {$c['from']} → {$c['to']}", $t['changes'])];
            $lines[] = 'You are on this plan, so this applies to your company now. Nobody is removed. Your usage and what is included are on the Billing & plan page.';
            $note && $lines[] = $note;
            $this->notices->tell($tenant, "Your plan {$t['name']} has changed", $lines);
            $n++;
        }

        return $n;
    }

    /** @param  array<string, mixed>  $t */
    private function describe(array $t): string
    {
        $what = implode(', ', array_map(fn (array $c) => "{$c['label']} {$c['from']} → {$c['to']}", $t['changes']));

        return ($t['was'] !== $t['name'] ? "{$t['was']} (now {$t['name']})" : $t['name']).": {$what}";
    }
}
