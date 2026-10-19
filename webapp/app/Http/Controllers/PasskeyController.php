<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Passkey;
use App\Services\Auth\PasskeyFailed;
use App\Services\Auth\SignIn;
use App\Services\Auth\SignInSecurity;
use App\Services\Auth\WebAuthn;
use App\Services\Tenancy\CompanySession;
use App\Support\NoticeMail;
use App\Support\Tenancy\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Passkeys: signing in with a fingerprint, face, device PIN or security key (JSON, called by the sign-in page and the security page). */
class PasskeyController extends Controller
{
    public function __construct(private readonly WebAuthn $webauthn, private readonly SignInSecurity $security, private readonly CompanySession $companies) {}

    /** Sign-in page: the challenge. */
    public function loginOptions(Request $request): JsonResponse
    {
        $this->enabled();

        return response()->json($this->webauthn->authenticationOptions($request));
    }

    /** Sign-in page: the device's answer. A passkey is a password and a second step in one, so no code is asked for after it. */
    public function login(Request $request, SignIn $signIn): JsonResponse
    {
        $this->enabled();
        try {
            $passkey = $this->webauthn->verifyAuthentication($request, $request->validate(['id' => ['required', 'string'], 'response' => ['required', 'array']]));
        } catch (PasskeyFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $identity = AccountUser::whereKey($passkey->account_user_id)->first();
        if (! $identity || ! $identity->isActive()) {
            return response()->json(['message' => 'This account is not active. Ask your administrator.'], 422);
        }
        $redirect = $signIn->proven($request, $identity, false, secondStepDone: true, method: 'passkey');

        return response()->json(['redirect' => $redirect->getTargetUrl()]);
    }

    /** Security page: the challenge for adding a passkey. */
    public function registerOptions(Request $request): JsonResponse
    {
        $this->enabled();
        $identity = $this->companies->identity($request) ?? abort(401);

        return response()->json($this->webauthn->registrationOptions($request, $identity, Company::platformBranding()['name']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->enabled();
        $identity = $this->companies->identity($request) ?? abort(401);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:80'], 'id' => ['required', 'string'], 'response' => ['required', 'array']]);
        try {
            $made = $this->webauthn->verifyRegistration($request, $data);
        } catch (PasskeyFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Passkey::create($made + ['account_user_id' => $identity->id, 'name' => trim((string) ($data['name'] ?? '')) ?: 'This device', 'last_used_at' => null]);
        NoticeMail::send($identity->email, 'A passkey was added to your account', ["Hi {$identity->name},", 'A passkey was added to your account, so you can sign in with your fingerprint, face or device PIN. If this was not you, change your password and remove it in your sign-in security page.']);

        return response()->json(['added' => true], 201);
    }

    public function destroy(Request $request, Passkey $passkey): JsonResponse
    {
        $identity = $this->companies->identity($request) ?? abort(401);
        abort_unless($passkey->account_user_id === $identity->id, 404);
        if (! Hash::check((string) $request->input('password'), $identity->password)) {
            return response()->json(['message' => 'That is not your current password.', 'errors' => ['password' => ['That is not your current password.']]], 422);
        }
        $passkey->delete();

        return response()->json(null, 204);
    }

    private function enabled(): void
    {
        abort_unless($this->security->passkeysEnabled(), 404);
    }
}
