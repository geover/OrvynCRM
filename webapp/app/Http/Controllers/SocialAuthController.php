<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\SocialIdentity;
use App\Services\Auth\SignIn;
use App\Services\Auth\SocialLogin;
use App\Services\Auth\SocialLoginFailed;
use App\Services\Auth\SocialProviders;
use App\Services\Tenancy\CompanySession;
use App\Support\NoticeMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** "Continue with Google / Microsoft / GitHub / single sign-on": signing in with an account somebody already has, and connecting one to a signed-in account. */
class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialProviders $providers, private readonly SocialLogin $social, private readonly CompanySession $companies) {}

    /** From the sign-in page. */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->providers->active($provider), 404);

        return $this->away($request, $provider, 'login', fn () => $this->fail($request, 'We could not reach the provider. Try again in a moment.'));
    }

    /** From the security page of a signed-in person: connect this provider to their account. */
    public function link(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->providers->active($provider), 404);
        $identity = $this->companies->identity($request) ?? abort(401);

        $return = $request->query('return') === 'platform' ? '/platform/security' : '/security';
        $request->session()->put('social.return', $return);                 // where to land even when the provider says no

        return $this->away($request, $provider, 'link', fn () => $this->fail($request, 'We could not reach the provider. Try again in a moment.', $return), ['identity' => $identity->id, 'return' => $return]);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            $profile = $this->social->profile($request);
            if ($profile['key'] !== $provider) {
                throw new SocialLoginFailed('That sign-in could not be checked. Try again.');
            }

            return $profile['mode'] === 'link' ? $this->connect($request, $profile) : $this->signIn($request, $profile);
        } catch (SocialLoginFailed $e) {
            return $this->fail($request, $e->getMessage(), $request->session()->pull('social.return'));
        }
    }

    /** Takes one provider off the account (the password is asked for again). */
    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $identity = $this->companies->identity($request) ?? abort(401);
        $password = $request->validate(['password' => ['required', 'string']])['password'];
        if (! Hash::check($password, $identity->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your current password.']);
        }
        SocialIdentity::where('account_user_id', $identity->id)->where('provider', $provider)->delete();

        return back()->with('success', 'Disconnected.');
    }

    /** @param  array<string, mixed>  $profile */
    private function signIn(Request $request, array $profile): RedirectResponse
    {
        $name = $this->providers->name($profile['key']);
        $known = SocialIdentity::where('provider', $profile['key'])->where('provider_user_id', $profile['sub'])->first();
        $identity = $known?->account;

        if (! $identity) {
            if (! $profile['verified'] || ! $profile['email']) {
                throw new SocialLoginFailed("Your {$name} account has no verified e-mail address, so we cannot match it to an account here.");
            }
            $identity = AccountUser::where('email', $profile['email'])->first()
                ?? throw new SocialLoginFailed("There is no account for {$profile['email']}. Ask your administrator to add you, or sign in another way.");
            if (! $identity->isActive()) {
                throw new SocialLoginFailed('This account is not active. Ask your administrator.');
            }
            SocialIdentity::updateOrCreate(['account_user_id' => $identity->id, 'provider' => $profile['key']], ['provider_user_id' => $profile['sub'], 'email' => $profile['email']]);
        }
        if (! $identity->isActive()) {
            throw new SocialLoginFailed('This account is not active. Ask your administrator.');
        }
        SocialIdentity::where('account_user_id', $identity->id)->where('provider', $profile['key'])->update(['last_used_at' => now(), 'email' => $profile['email']]);

        return app(SignIn::class)->proven($request, $identity, false, method: 'social');
    }

    /** @param  array<string, mixed>  $profile */
    private function connect(Request $request, array $profile): RedirectResponse
    {
        $identity = $this->companies->identity($request);
        $return = (string) ($profile['extra']['return'] ?? '/security');
        if (! $identity || $identity->id !== ($profile['extra']['identity'] ?? null)) {
            throw new SocialLoginFailed('Sign in again, then connect the account.');
        }
        $taken = SocialIdentity::where('provider', $profile['key'])->where('provider_user_id', $profile['sub'])->where('account_user_id', '!=', $identity->id)->exists();
        if ($taken) {
            throw new SocialLoginFailed('That account is already connected to somebody else.');
        }
        SocialIdentity::updateOrCreate(['account_user_id' => $identity->id, 'provider' => $profile['key']], ['provider_user_id' => $profile['sub'], 'email' => $profile['email'], 'last_used_at' => now()]);
        $name = $this->providers->name($profile['key']);
        NoticeMail::send($identity->email, "{$name} was connected to your account", ["Hi {$identity->name},", "A {$name} account was connected to your account, so you can sign in with it. If this was not you, change your password and disconnect it in your sign-in security page."]);

        $request->session()->forget('social.return');

        return redirect($return)->with('success', "{$name} is connected.");
    }

    private function away(Request $request, string $provider, string $mode, \Closure $onFailure, array $extra = []): RedirectResponse
    {
        try {
            return redirect()->away($this->social->authorizationUrl($request, $provider, $mode, $extra));
        } catch (\Throwable $e) {
            report($e);

            return $onFailure();
        }
    }

    private function fail(Request $request, string $message, ?string $return = null): RedirectResponse
    {
        if ($this->companies->identity($request)) {
            return redirect($return ?? ($request->session()->get('tenant_id') ? '/security' : '/platform/security'))->with('warning', $message);
        }

        return redirect('/login')->withErrors(['email' => $message]);
    }
}
