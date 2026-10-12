<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountUser;
use App\Models\User;
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
    public function token(Request $request, CompanySession $companies): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'password' => ['required', 'string'], 'device' => ['nullable', 'string', 'max:60'],
            'company' => ['nullable', 'string', 'max:63'],
        ]);

        LoginThrottle::ensureNotLocked($request);
        $identity = AccountUser::where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $identity || ! $identity->isActive() || ! Hash::check($data['password'], $identity->password)) {
            LoginThrottle::fail($request);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        LoginThrottle::succeed($request);

        $mine = $companies->companiesFor($identity);
        $tenant = isset($data['company']) ? $mine->firstWhere('slug', $data['company']) : ($mine->count() === 1 ? $mine->first() : null);
        if (! $tenant) {
            throw ValidationException::withMessages(['company' => $mine->count() > 1
                ? 'You belong to several companies; send "company" with one of: '.$mine->pluck('slug')->implode(', ').'.'
                : 'No active company found for this account.']);
        }

        return $tenant->run(function () use ($identity, $data) {
            $user = User::where('central_user_id', $identity->id)->first();
            abort_unless($user && $user->isActive(), 403, 'Your access to this company is suspended.');
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
