<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountUser;
use App\Models\User;
use App\Services\Auth\SignInSecurity;
use App\Services\Auth\TwoFactor;
use App\Services\Tenancy\CompanySession;
use App\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Token login for API/mobile clients. (SPA/Inertia login uses the session guard in WebAuthController.)
     * A token belongs to one company: pass `company` (its slug) when the person belongs to several.
     */
    public function token(Request $request, CompanySession $companies, TwoFactor $twoFactor, SignInSecurity $security): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'password' => ['required', 'string'], 'device' => ['nullable', 'string', 'max:60'],
            'company' => ['nullable', 'string', 'max:63'], 'otp' => ['nullable', 'string', 'max:32'],
        ]);

        LoginThrottle::ensureNotLocked($request);
        $identity = AccountUser::where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $identity || ! $identity->isActive() || ! Hash::check($data['password'], $identity->password)) {
            LoginThrottle::fail($request);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        LoginThrottle::succeed($request);

        // the second step: a token is as good as a session, so it needs the same proof (send "otp": the app's code or a recovery code)
        if ($twoFactor->isEnabled($identity)) {
            if (blank($data['otp'] ?? null)) {
                return response()->json(['message' => 'Two-step verification is on for this account: send "otp" with the code from your authenticator app.', 'two_factor_required' => true, 'errors' => ['otp' => ['The code is required.']]], 422);
            }
            if (! $twoFactor->verify($identity, $data['otp'])) {
                throw ValidationException::withMessages(['otp' => 'That code is not right.']);
            }
        }

        $mine = $companies->companiesFor($identity);
        $tenant = isset($data['company']) ? $mine->firstWhere('slug', $data['company']) : ($mine->count() === 1 ? $mine->first() : null);
        if (! $tenant) {
            throw ValidationException::withMessages(['company' => $mine->count() > 1
                ? 'You belong to several companies; send "company" with one of: '.$mine->pluck('slug')->implode(', ').'.'
                : 'No active company found for this account.']);
        }

        return $tenant->run(function () use ($identity, $data, $twoFactor, $security) {
            $user = User::where('central_user_id', $identity->id)->first();
            abort_unless($user && $user->isActive(), 403, 'Your access to this company is suspended.');
            abort_if(! $twoFactor->isEnabled($identity) && $security->requiresTwoFactor($identity, $user->isAdmin()), 403, 'Your administrator requires two-step verification. Sign in to the app and set it up first.');
            $token = $user->createToken($data['device'] ?? 'api', ['*'], now()->addDays(30));
            $token->accessToken->forceFill(['account_user_id' => $identity->id])->save();

            return response()->json(['token' => $token->plainTextToken, 'user' => $user->only(['id', 'name', 'email', 'role']), 'company' => tenant('slug')]);
        });
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => $u->only(['id', 'name', 'email', 'role', 'timezone', 'signature']) + ['is_admin' => $u->isAdmin()]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        Auth::guard('web')->logout();

        return response()->json(null, 204);
    }
}
