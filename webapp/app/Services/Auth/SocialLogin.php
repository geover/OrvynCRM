<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The OAuth 2.0 / OpenID Connect "authorization code" flow, with every protection the standards ask for: an unguessable `state` tied to
 * this browser session (against forged callbacks), PKCE (a code that is stolen cannot be redeemed), a `nonce` (a token cannot be replayed
 * into another login) and the e-mail only counts when the provider says it is verified. Tokens are exchanged server to server over TLS,
 * so the secret never reaches the browser.
 */
class SocialLogin
{
    public const SESSION = 'social.pending';

    public function __construct(private readonly SocialProviders $providers) {}

    /**
     * Where to send the browser. `$mode` is "login" (the sign-in page) or "link" (a signed-in person adding the provider to their account).
     *
     * @param  array<string, mixed>  $extra  kept in the session for the callback (where to return to, who is linking)
     */
    public function authorizationUrl(Request $request, string $key, string $mode, array $extra = []): string
    {
        $endpoints = $this->providers->endpoints($key);
        $state = Str::random(48);
        $verifier = Str::random(96);
        $nonce = Str::random(32);
        $request->session()->put(self::SESSION, ['key' => $key, 'mode' => $mode, 'state' => $state, 'verifier' => $verifier, 'nonce' => $nonce, 'until' => time() + 600] + $extra);

        $query = [
            'client_id' => $this->providers->clientId($key), 'redirect_uri' => $this->providers->redirectUri($key), 'response_type' => 'code',
            'scope' => $this->providers->scope($key), 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ];
        if ($this->providers->isOidc($key)) {
            $query['nonce'] = $nonce;
        }
        if ($key === 'google') {
            $query['prompt'] = 'select_account';
        }

        return $endpoints['authorize'].(str_contains($endpoints['authorize'], '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Checks the callback and returns who the provider says this is. The pending login is used up either way.
     *
     * @return array{key: string, mode: string, sub: string, email: ?string, verified: bool, name: ?string, extra: array<string, mixed>}
     *
     * @throws SocialLoginFailed with a message that is safe to show
     */
    public function profile(Request $request): array
    {
        $pending = $request->session()->pull(self::SESSION);
        if (! is_array($pending) || ($pending['until'] ?? 0) < time()) {
            throw new SocialLoginFailed('That sign-in took too long or was not started here. Try again.');
        }
        $key = $pending['key'];
        if (! hash_equals((string) $pending['state'], (string) $request->query('state')) || ! $this->providers->active($key)) {
            throw new SocialLoginFailed('That sign-in could not be checked. Try again.');
        }
        if ($request->query('error')) {
            throw new SocialLoginFailed($request->query('error') === 'access_denied' ? 'The sign-in was cancelled.' : 'The provider did not let you sign in.');
        }
        $code = (string) $request->query('code');
        if ($code === '') {
            throw new SocialLoginFailed('The provider did not send a sign-in code. Try again.');
        }

        try {
            $endpoints = $this->providers->endpoints($key);
            $token = Http::asForm()->acceptJson()->timeout(15)->post($endpoints['token'], [
                'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->providers->redirectUri($key),
                'client_id' => $this->providers->clientId($key), 'client_secret' => $this->providers->secret($key), 'code_verifier' => $pending['verifier'],
            ]);
            $access = $token->json('access_token');
            if (! $token->ok() || ! is_string($access) || $access === '') {
                throw new SocialLoginFailed('The provider refused the sign-in. Try again.');
            }

            $claims = $this->providers->isOidc($key) ? $this->idToken((string) $token->json('id_token'), $key, $endpoints['issuers'], (string) $pending['nonce']) : [];
            $profile = $key === 'github' ? $this->github($access) : $this->userinfo($endpoints['userinfo'], $access, $key, $claims);
        } catch (SocialLoginFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new SocialLoginFailed('We could not reach the provider. Try again in a moment.');
        }

        return $profile + ['key' => $key, 'mode' => $pending['mode'], 'extra' => array_diff_key($pending, array_flip(['key', 'mode', 'state', 'verifier', 'nonce', 'until']))];
    }

    /**
     * The ID token came straight from the provider's token endpoint over TLS, which is how the OpenID Connect standard allows it to be
     * trusted without checking its signature: what is checked is whom it is for, who issued it, that it is fresh and that it belongs to
     * THIS login (nonce).
     *
     * @param  list<string>  $issuers
     * @return array<string, mixed>
     */
    private function idToken(string $jwt, string $key, array $issuers, string $nonce): array
    {
        $parts = explode('.', $jwt);
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        if (! is_array($claims)) {
            throw new SocialLoginFailed('The provider sent an unreadable sign-in token.');
        }
        $audience = (array) ($claims['aud'] ?? []);
        if (! in_array($this->providers->clientId($key), $audience, true)
            || ($claims['exp'] ?? 0) < time()
            || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            || ($issuers !== [] && ! in_array(rtrim((string) ($claims['iss'] ?? ''), '/'), array_map(fn ($i) => rtrim($i, '/'), $issuers), true))) {
            throw new SocialLoginFailed('The sign-in token did not check out. Try again.');
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{sub: string, email: ?string, verified: bool, name: ?string}
     */
    private function userinfo(string $url, string $access, string $key, array $claims): array
    {
        $info = Http::withToken($access)->acceptJson()->timeout(15)->get($url)->json();
        if (! is_array($info) || ! filled($info['sub'] ?? null) || ($claims !== [] && (string) $claims['sub'] !== (string) $info['sub'])) {
            throw new SocialLoginFailed('The provider did not say who you are.');
        }
        $email = filled($info['email'] ?? null) ? mb_strtolower(trim((string) $info['email'])) : null;
        $verified = filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // Microsoft does not say it; an address is only as good as the directory it comes from, so only a named directory counts
        if ($key === 'microsoft') {
            $verified = $this->providers->tenantIsSpecific() && ($claims['tid'] ?? null) !== null && $email !== null;
        }

        return ['sub' => (string) $info['sub'], 'email' => $email, 'verified' => $verified && $email !== null, 'name' => $info['name'] ?? null];
    }

    /** @return array{sub: string, email: ?string, verified: bool, name: ?string} */
    private function github(string $access): array
    {
        $user = Http::withToken($access)->acceptJson()->timeout(15)->get('https://api.github.com/user')->json();
        if (! is_array($user) || ! filled($user['id'] ?? null)) {
            throw new SocialLoginFailed('The provider did not say who you are.');
        }
        $emails = Http::withToken($access)->acceptJson()->timeout(15)->get('https://api.github.com/user/emails')->json();
        $primary = collect(is_array($emails) ? $emails : [])->first(fn ($e) => ($e['primary'] ?? false) && ($e['verified'] ?? false));

        return ['sub' => (string) $user['id'], 'email' => $primary ? mb_strtolower((string) $primary['email']) : null, 'verified' => (bool) $primary, 'name' => $user['name'] ?? $user['login'] ?? null];
    }
}
