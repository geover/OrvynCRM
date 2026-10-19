<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Services\Auth\SignIn;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The second step of signing in: the code from the authenticator app, or a recovery code. */
class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $identity = $this->pending($request);
        if (! $identity) {
            return redirect('/login');
        }

        return Inertia::render('Auth/TwoFactorChallenge', ['email' => $identity->email]);
    }

    public function store(Request $request, TwoFactor $twoFactor, SignIn $signIn): RedirectResponse
    {
        $identity = $this->pending($request);
        if (! $identity) {
            return redirect('/login')->withErrors(['email' => 'That took too long. Sign in again.']);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);

        if ($twoFactor->lockedOut($identity)) {
            $request->session()->forget(SignIn::PENDING);

            return redirect('/login')->withErrors(['email' => 'Too many wrong codes. Wait a few minutes, then sign in again.']);
        }
        if (! $twoFactor->verify($identity, $data['code'])) {
            return back()->withErrors(['code' => 'That code is not right. Check the time on your phone and try again, or use a recovery code.']);
        }

        return $signIn->complete($request, $identity, (bool) $request->session()->get(SignIn::PENDING)['remember'], (string) ($request->session()->get(SignIn::PENDING)['method'] ?? 'password'), twoStep: true);
    }

    private function pending(Request $request): ?AccountUser
    {
        $pending = $request->session()->get(SignIn::PENDING);
        if (! is_array($pending) || ($pending['until'] ?? 0) < time()) {
            $request->session()->forget(SignIn::PENDING);

            return null;
        }

        return AccountUser::whereKey($pending['id'])->where('status', 'active')->first();
    }
}
