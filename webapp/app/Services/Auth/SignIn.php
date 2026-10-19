<?php

namespace App\Services\Auth;

use App\Models\AccountUser;
use App\Services\Tenancy\CompanySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What happens once someone has proved who they are, whichever way they did it (password, an identity provider, a passkey):
 * ask for the second step if they use one, otherwise open the session and take them to their company (or the chooser).
 */
class SignIn
{
    public const PENDING = 'two_factor.pending';

    public function __construct(private readonly CompanySession $companies, private readonly TwoFactor $twoFactor) {}

    /** @param  bool  $secondStepDone  the way they came in already counts as strong (a passkey): no code is asked for */
    public function proven(Request $request, AccountUser $identity, bool $remember, bool $secondStepDone = false, string $method = 'password'): RedirectResponse
    {
        if (! $secondStepDone && $this->twoFactor->isEnabled($identity)) {
            // Not signed in yet: only this browser session knows who is being asked, for ten minutes
            $request->session()->regenerate();
            $request->session()->put(self::PENDING, ['id' => $identity->id, 'remember' => $remember, 'until' => now()->addMinutes(10)->getTimestamp(), 'method' => $method]);

            return redirect('/two-factor-challenge');
        }

        return $this->complete($request, $identity, $remember, $method, twoStep: $secondStepDone || $this->twoFactor->isEnabled($identity));
    }

    /** Opens the session. */
    public function complete(Request $request, AccountUser $identity, bool $remember, string $method = 'password', bool $twoStep = false): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->forget(self::PENDING);
        $request->session()->put('identity_id', $identity->id);
        app(AccountSessions::class)->open($request, $identity, $method, $twoStep);                 // the record of this sign-in

        $mine = $this->companies->companiesFor($identity);
        if ($mine->count() === 1) {
            try {
                return $this->companies->enter($request, $identity, $mine->first(), $remember);
            } catch (ValidationException $e) {
                // e.g. suspended in their one company: the sign-in page only shows the email field's error, so say it there
                $request->session()->forget('identity_id');

                return redirect('/login')->withErrors(['email' => collect($e->errors())->flatten()->first()])->withInput(['email' => $identity->email]);
            }
        }
        if ($mine->isEmpty()) {
            if ($identity->is_platform_admin) {
                return redirect('/platform');
            }
            $request->session()->forget('identity_id');

            return redirect('/login')->withErrors(['email' => 'Your account does not belong to an active company. Ask your administrator.'])->withInput(['email' => $identity->email]);
        }

        $request->session()->put('remember', $remember);

        return redirect('/choose-company');
    }
}
