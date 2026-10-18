<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Passkey;
use App\Models\SocialIdentity;
use App\Services\Auth\SignInSecurity;
use App\Services\Auth\SocialProviders;
use App\Services\Auth\TwoFactor;
use App\Services\Tenancy\CompanySession;
use App\Support\NoticeMail;
use App\Support\Tenancy\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My sign-in security" for the person who is signed in, in the company or in the platform area: the same page and rules for everyone,
 * because the identity (and so the second step) is one per person.
 */
class AccountSecurityController extends Controller
{
    private const PENDING_SECRET = 'two_factor.setup_secret';

    public function __construct(private readonly TwoFactor $twoFactor, private readonly SignInSecurity $security) {}

    /** The page inside a company (any role). */
    public function company(Request $request, CompanySession $companies): Response
    {
        return Inertia::render('Security', ['security' => $this->status($request, $companies->identity($request) ?? abort(401))]);
    }

    /** The page in the platform area. */
    public function platform(Request $request): Response
    {
        return Inertia::render('Platform/Security', ['security' => $this->status($request, $request->attributes->get('platform_identity') ?? abort(401))]);
    }

    /** Step 1: a new secret to scan (it is only kept in this browser session until a code proves the app has it). */
    public function start(Request $request, CompanySession $companies): RedirectResponse
    {
        $identity = $this->identity($request, $companies);
        if ($this->twoFactor->isEnabled($identity)) {
            return back()->with('warning', 'Two-step verification is already on.');
        }
        $request->session()->put(self::PENDING_SECRET, $this->twoFactor->newSecret());

        return back();
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING_SECRET);

        return back();
    }

    /** Step 2: the first code from the app turns it on and hands out the recovery codes (shown once). */
    public function confirm(Request $request, CompanySession $companies): RedirectResponse
    {
        $identity = $this->identity($request, $companies);
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $secret = $request->session()->get(self::PENDING_SECRET);
        if (! $secret) {
            return back()->with('warning', 'Start again: the setup took too long.');
        }
        if ($this->twoFactor->lockedOut($identity)) {
            return back()->withErrors(['code' => 'Too many wrong codes. Wait a few minutes.']);
        }
        $codes = $this->twoFactor->confirm($identity, $secret, $data['code']);
        if ($codes === null) {
            RateLimiter::hit('two-factor:'.$identity->getKey(), 300);

            return back()->withErrors(['code' => 'That code is not right. Check the time on your phone and try again.']);
        }
        $request->session()->forget(self::PENDING_SECRET);
        $this->tell($identity, 'Two-step verification is on', 'Two-step verification was turned on for your account.');

        return back()->with('success', 'Two-step verification is on.')->with('recovery_codes', $codes);
    }

    /** New recovery codes (the old ones stop working). Asks for the password again. */
    public function recoveryCodes(Request $request, CompanySession $companies): RedirectResponse
    {
        $identity = $this->identity($request, $companies);
        $this->confirmPassword($request, $identity);
        abort_unless($this->twoFactor->isEnabled($identity), 422);

        return back()->with('success', 'New recovery codes. The old ones no longer work.')->with('recovery_codes', $this->twoFactor->regenerateRecoveryCodes($identity));
    }

    public function disable(Request $request, CompanySession $companies): RedirectResponse
    {
        $identity = $this->identity($request, $companies);
        $this->confirmPassword($request, $identity);
        if ($this->security->requiresTwoFactor($identity, $this->companyAdmin())) {
            return back()->with('warning', 'Your administrator requires two-step verification, so it cannot be turned off.');
        }
        $this->twoFactor->disable($identity);
        $this->tell($identity, 'Two-step verification is off', 'Two-step verification was turned off for your account. If this was not you, change your password and turn it on again.');

        return back()->with('success', 'Two-step verification is off.');
    }

    /** @return array<string, mixed> */
    private function status(Request $request, AccountUser $identity): array
    {
        $secret = $request->session()->get(self::PENDING_SECRET);
        $issuer = Company::platformBranding()['name'];        // one identity spans every company, so the app lists it under the platform's name

        return [
            'enabled' => $this->twoFactor->isEnabled($identity),
            'since' => $identity->two_factor_confirmed_at?->toIso8601String(),
            'recovery_left' => $this->twoFactor->recoveryCodesLeft($identity),
            'required' => $this->security->requiresTwoFactor($identity, $this->companyAdmin()),
            'pending' => $secret ? ['secret' => $secret, 'uri' => $this->twoFactor->uri($identity, $secret, $issuer)] : null,
            'recovery_codes' => $request->session()->get('recovery_codes'),
            'passkeys_enabled' => $this->security->passkeysEnabled(),
            'passkeys' => Passkey::where('account_user_id', $identity->id)->orderBy('created_at')->get()->map(fn (Passkey $p) => [
                'id' => $p->id, 'name' => $p->name, 'created_at' => $p->created_at?->toIso8601String(), 'last_used_at' => $p->last_used_at?->toIso8601String(), 'backed_up' => $p->backed_up,
            ])->values(),
            'social' => $this->connections($identity),
        ];
    }

    /** @return list<array{key: string, name: string, connected: bool, email: ?string}> the providers people can sign in with here, and which are connected to this account */
    private function connections(AccountUser $identity): array
    {
        $mine = SocialIdentity::where('account_user_id', $identity->id)->get()->keyBy('provider');

        return array_map(fn (array $p) => $p + ['connected' => $mine->has($p['key']), 'email' => $mine->get($p['key'])?->email], app(SocialProviders::class)->offered());
    }

    private function identity(Request $request, CompanySession $companies): AccountUser
    {
        return $companies->identity($request) ?? abort(401);
    }

    private function companyAdmin(): ?bool
    {
        return tenancy()->initialized ? (bool) Auth::guard('web')->user()?->isAdmin() : null;
    }

    private function confirmPassword(Request $request, AccountUser $identity): void
    {
        $password = $request->validate(['password' => ['required', 'string']])['password'];
        if (! Hash::check($password, $identity->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your current password.']);
        }
    }

    private function tell(AccountUser $identity, string $subject, string $line): void
    {
        NoticeMail::send($identity->email, $subject, ["Hi {$identity->name},", $line]);
    }
}
