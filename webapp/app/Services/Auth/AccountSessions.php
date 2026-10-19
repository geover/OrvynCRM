<?php

namespace App\Services\Auth;

use App\Models\AccountSession;
use App\Models\AccountUser;
use Illuminate\Http\Request;

/**
 * Keeps the record of who signed in, when, how and for how long. A session is opened where a sign-in completes (SignIn::complete), kept fresh by the
 * requests that follow (at most once a minute, and not by the page's own background polling, which would make an open tab look like work), and closed on sign-out.
 */
class AccountSessions
{
    public const KEY = 'account_session_id';

    private const SEEN = 'account_session_seen';

    /** A request to these is the page asking by itself: it does not show that somebody is there. */
    private const BACKGROUND = '#(/poll|/queue|/unread|/heartbeat)(/|$|\?)#';

    public function open(Request $request, AccountUser $identity, string $method = 'password', bool $twoStep = false): void
    {
        $this->close($request);                                           // a sign-in on top of an open one ends the old one
        $session = AccountSession::create([
            'account_user_id' => $identity->id, 'started_at' => now(), 'last_seen_at' => now(), 'method' => $method, 'two_step' => $twoStep,
            'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        $request->session()->put([self::KEY => $session->id, self::SEEN => now()->getTimestamp()]);
    }

    /** Called on every request of a signed-in person. */
    public function touch(Request $request): void
    {
        $id = $request->session()->get(self::KEY);
        if (! $id || preg_match(self::BACKGROUND, '/'.ltrim($request->path(), '/').'/') || now()->getTimestamp() - (int) $request->session()->get(self::SEEN, 0) < 60) {
            return;
        }
        $request->session()->put(self::SEEN, now()->getTimestamp());
        AccountSession::whereKey($id)->whereNull('ended_at')->update(['last_seen_at' => now()]);
    }

    /** The sign-in is now working in this company (the last one it entered). */
    public function enterCompany(Request $request, string $tenantId): void
    {
        if ($id = $request->hasSession() ? $request->session()->get(self::KEY) : null) {
            AccountSession::whereKey($id)->update(['tenant_id' => $tenantId]);
        }
    }

    public function close(Request $request): void
    {
        if ($id = $request->hasSession() ? $request->session()->get(self::KEY) : null) {
            AccountSession::whereKey($id)->whereNull('ended_at')->update(['last_seen_at' => now(), 'ended_at' => now()]);
            $request->session()->forget([self::KEY, self::SEEN]);
        }
    }
}
