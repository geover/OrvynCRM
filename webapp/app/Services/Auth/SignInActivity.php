<?php

namespace App\Services\Auth;

use App\Models\AccountSession;
use App\Models\Passkey;
use App\Models\SocialIdentity;
use Illuminate\Support\Collection;

/**
 * What the record of sign-ins (AccountSession) says about each person: are they online now, active in the last 30 days, inactive or never seen; their last
 * sign-in and how it was made; sign-ins and time spent in the last 30 days; their latest sessions; how they are protected. For a company, only the
 * sessions that worked in that company count. Used by the platform's Administrators page and by both People pages.
 */
class SignInActivity
{
    /** @return array<string, mixed> what is known about somebody with no sign-ins on record */
    public function blank(): array
    {
        return ['passkeys' => 0, 'sso' => [], 'activity' => 'never', 'last_login_at' => null, 'last_seen_at' => null, 'first_login_at' => null, 'last_method' => null, 'last_two_step' => false, 'last_ip' => null,
            'last_agent' => null, 'sessions_total' => 0, 'sessions_30d' => 0, 'seconds_30d' => 0, 'average_seconds' => 0, 'longest_seconds' => 0, 'recent' => []];
    }

    /**
     * @param  Collection<int, int>  $ids  central account ids
     * @return array<int, array<string, mixed>> by account id
     */
    public function for(Collection $ids, ?string $tenantId = null): array
    {
        $ids = $ids->filter()->unique()->values();
        $scope = fn ($q) => $q->whereIn('account_user_id', $ids)->when($tenantId, fn ($w) => $w->where('tenant_id', $tenantId));

        $totals = $scope(AccountSession::query())->selectRaw('account_user_id, count(*) as n, min(started_at) as first_at')->groupBy('account_user_id')->get()->keyBy('account_user_id');
        $month = $scope(AccountSession::query())->where('started_at', '>=', now()->subDays(30))->get()->groupBy('account_user_id');
        $recent = $scope(AccountSession::query())->orderByDesc('started_at')->limit(max(400, $ids->count() * 12))->get()->groupBy('account_user_id');
        $passkeys = Passkey::whereIn('account_user_id', $ids)->selectRaw('account_user_id, count(*) as n')->groupBy('account_user_id')->pluck('n', 'account_user_id');
        $sso = SocialIdentity::whereIn('account_user_id', $ids)->get()->groupBy('account_user_id');
        $live = fn (AccountSession $x) => $x->ended_at === null && $x->last_seen_at->gte(now()->subMinutes(5));

        return $ids->mapWithKeys(function (int $id) use ($totals, $month, $recent, $passkeys, $sso, $live) {
            $mine = $recent[$id] ?? collect();
            $last = $mine->first();
            $thirty = $month[$id] ?? collect();
            $seconds = (int) $thirty->sum(fn (AccountSession $x) => $x->seconds());

            return [$id => [
                'passkeys' => (int) ($passkeys[$id] ?? 0), 'sso' => ($sso[$id] ?? collect())->pluck('provider')->values()->all(),
                // activity: online now, signed in within the last 30 days (active), longer ago (inactive), or never
                'activity' => $last === null ? 'never' : ($live($last) ? 'online' : ($last->started_at->gte(now()->subDays(30)) ? 'active' : 'inactive')),
                'last_login_at' => $last?->started_at->toIso8601String(), 'last_seen_at' => $last?->last_seen_at->toIso8601String(), 'first_login_at' => $totals[$id]->first_at ?? null,
                'last_method' => $last?->method, 'last_two_step' => (bool) $last?->two_step, 'last_ip' => $last?->ip, 'last_agent' => $last?->user_agent,
                'sessions_total' => (int) ($totals[$id]->n ?? 0), 'sessions_30d' => $thirty->count(), 'seconds_30d' => $seconds,
                'average_seconds' => $thirty->count() ? intdiv($seconds, $thirty->count()) : 0,
                'longest_seconds' => (int) $thirty->max(fn (AccountSession $x) => $x->seconds()),
                'recent' => $mine->take(8)->map(fn (AccountSession $x) => [
                    'id' => $x->id, 'started_at' => $x->started_at->toIso8601String(), 'seconds' => $x->seconds(), 'live' => $live($x), 'signed_out' => $x->ended_at !== null,
                    'method' => $x->method, 'two_step' => $x->two_step, 'ip' => $x->ip, 'agent' => $x->user_agent,
                ])->values()->all(),
            ]];
        })->all();
    }
}
