<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\PersonalAccessToken;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Password resets act on the central identity, so one reset covers every company the person belongs to. */
class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['email' => request()->query('email')]);
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        PasswordBroker::sendResetLink($request->only('email'));

        // Same answer whether or not the account exists (no user enumeration).
        return back()->with('success', 'If that address has an account, a reset link is on its way.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);

        $status = PasswordBroker::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (AccountUser $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            // every API token of this person, in every company
            PersonalAccessToken::withoutGlobalScopes()->where('account_user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });

        return $status === PasswordBroker::PASSWORD_RESET
            ? redirect('/login')->with('success', 'Password updated. You can sign in now.')
            : back()->withErrors(['email' => __($status)]);
    }
}
