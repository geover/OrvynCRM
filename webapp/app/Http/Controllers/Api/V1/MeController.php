<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Services\Tenancy\CompanyUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Profile, signature and personal API tokens (replaces macanta api_key / user settings screen). */
class MeController extends Controller
{
    public function update(Request $request, CompanyUsers $users): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone:all'],
            'signature' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', Password::min(10)->mixedCase()->numbers()],
        ]);

        $identity = $users->identityOf($u);
        if (isset($d['password'])) {
            if (! $identity || ! Hash::check($d['current_password'], $identity->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            // The password belongs to the person, not to one company: it changes everywhere, and every API token except
            // the one making this request is revoked. A browser (session) login has a TransientToken with no id, so only
            // a real PersonalAccessToken can be "the current one".
            $current = $u->currentAccessToken();
            PersonalAccessToken::withoutGlobalScopes()->where('account_user_id', $identity->id)
                ->when($current instanceof \Laravel\Sanctum\PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))->delete();
            $identity->update(['password' => $d['password']]);
            unset($d['password']);
        }
        unset($d['current_password']);
        if (isset($d['name']) && $identity) {
            $identity->update(['name' => $d['name']]);
        }
        $u->update($d);

        return response()->json(['data' => $u->only(['id', 'name', 'email', 'timezone', 'signature', 'role'])]);
    }

    public function tokens(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->tokens()->latest()->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])]);
    }

    public function createToken(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $token = $request->user()->createToken($d['name'], ['*'], now()->addYear());
        $token->accessToken->forceFill(['account_user_id' => $request->user()->central_user_id])->save();   // revocable on a password reset

        // The plain-text token is shown exactly once.
        return response()->json(['token' => $token->plainTextToken, 'id' => $token->accessToken->id], 201);
    }

    public function deleteToken(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->whereKey($id)->delete();

        return response()->json(null, 204);
    }
}
