<?php

namespace App\Services\Auth;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The identity providers people can sign in with ("Continue with Google"): the platform switches each one on and enters the keys it got from
 * the provider. Google, Microsoft and GitHub are built in; "Custom OpenID Connect" is any other provider that speaks it (Okta, Auth0,
 * Keycloak, Entra ID, a company's own single sign-on). Secrets are stored encrypted and never shown again.
 */
class SocialProviders
{
    /** Tenants that accept ANY Microsoft account: their e-mail addresses are not verified, so they cannot be used to find an account. */
    private const SHARED_MICROSOFT_TENANTS = ['common', 'organizations', 'consumers'];

    private const DEFS = [
        'google' => ['name' => 'Google', 'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo', 'scope' => 'openid email profile', 'oidc' => true, 'issuers' => ['https://accounts.google.com', 'accounts.google.com']],
        'microsoft' => ['name' => 'Microsoft', 'authorize' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize', 'token' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token', 'userinfo' => 'https://graph.microsoft.com/oidc/userinfo', 'scope' => 'openid email profile', 'oidc' => true],
        'github' => ['name' => 'GitHub', 'authorize' => 'https://github.com/login/oauth/authorize', 'token' => 'https://github.com/login/oauth/access_token', 'userinfo' => 'https://api.github.com/user', 'scope' => 'read:user user:email', 'oidc' => false],
        'oidc' => ['name' => 'Single sign-on', 'scope' => 'openid email profile', 'oidc' => true],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DEFS);
    }

    public function exists(string $key): bool
    {
        return isset(self::DEFS[$key]);
    }

    /** What the platform's setting page shows (never the secret). @return array<string, mixed> */
    public function config(string $key): array
    {
        $def = self::DEFS[$key];

        return [
            'key' => $key, 'default_name' => $def['name'],
            'enabled' => $this->get($key, 'enabled') === '1',
            'client_id' => (string) $this->get($key, 'client_id'),
            'secret_set' => (bool) $this->get($key, 'client_secret'),
            'tenant' => (string) $this->get($key, 'tenant'),
            'issuer' => (string) $this->get($key, 'issuer'),
            'label' => (string) $this->get($key, 'label'),
            'active' => $this->active($key),
            'callback' => $this->redirectUri($key),
        ];
    }

    /**
     * An empty secret keeps the saved one; `clear_secret` removes it.
     *
     * @param  array<string, mixed>  $d
     */
    public function save(string $key, array $d): void
    {
        $this->put($key, 'enabled', ! empty($d['enabled']) ? '1' : '0');
        $this->put($key, 'client_id', filled($d['client_id'] ?? null) ? trim((string) $d['client_id']) : null);
        if ($key === 'microsoft') {
            $this->put($key, 'tenant', filled($d['tenant'] ?? null) ? trim((string) $d['tenant']) : null);
        }
        if ($key === 'oidc') {
            $this->put($key, 'issuer', filled($d['issuer'] ?? null) ? rtrim(trim((string) $d['issuer']), '/') : null);
            $this->put($key, 'label', filled($d['label'] ?? null) ? trim((string) $d['label']) : null);
            Cache::forget("oidc-discovery:{$this->get($key, 'issuer')}");
        }
        if (filled($d['client_secret'] ?? null)) {
            $this->put($key, 'client_secret', Crypt::encryptString(trim((string) $d['client_secret'])));
        }
        if (! empty($d['clear_secret'])) {
            $this->put($key, 'client_secret', null);
        }
    }

    /** Switched on with everything it needs saved. */
    public function active(string $key): bool
    {
        if (! $this->exists($key) || $this->get($key, 'enabled') !== '1' || ! filled($this->get($key, 'client_id')) || $this->secret($key) === null) {
            return false;
        }

        return match ($key) {
            'microsoft' => filled($this->get($key, 'tenant')),
            'oidc' => filled($this->get($key, 'issuer')),
            default => true,
        };
    }

    /** @return list<array{key: string, name: string}> what the sign-in page offers */
    public function offered(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            if ($this->active($key)) {
                $out[] = ['key' => $key, 'name' => $this->name($key)];
            }
        }

        return $out;
    }

    public function name(string $key): string
    {
        return $key === 'oidc' && filled($this->get($key, 'label')) ? (string) $this->get($key, 'label') : self::DEFS[$key]['name'];
    }

    public function clientId(string $key): string
    {
        return (string) $this->get($key, 'client_id');
    }

    public function secret(string $key): ?string
    {
        $saved = $this->get($key, 'client_secret');
        if (! $saved) {
            return null;
        }
        try {
            return Crypt::decryptString($saved);
        } catch (Throwable) {
            return null;                                   // saved under another APP_KEY
        }
    }

    public function redirectUri(string $key): string
    {
        return url("/auth/{$key}/callback");
    }

    public function isOidc(string $key): bool
    {
        return self::DEFS[$key]['oidc'];
    }

    public function scope(string $key): string
    {
        return self::DEFS[$key]['scope'];
    }

    /** Are the e-mail addresses this provider hands out proven? (Microsoft's only are when the sign-in is limited to one directory.) */
    public function tenantIsSpecific(): bool
    {
        $tenant = strtolower((string) $this->get('microsoft', 'tenant'));

        return $tenant !== '' && ! in_array($tenant, self::SHARED_MICROSOFT_TENANTS, true);
    }

    /**
     * @return array{authorize: string, token: string, userinfo: string, issuers: list<string>}
     *
     * @throws \RuntimeException when a custom provider cannot be reached or does not describe itself properly
     */
    public function endpoints(string $key): array
    {
        $def = self::DEFS[$key];
        if ($key === 'oidc') {
            return $this->discover((string) $this->get($key, 'issuer'));
        }
        $tenant = rawurlencode((string) $this->get($key, 'tenant'));
        $fill = fn (string $url) => str_replace('{tenant}', $tenant, $url);
        $issuers = $def['issuers'] ?? ($key === 'microsoft' ? ["https://login.microsoftonline.com/{$tenant}/v2.0"] : []);

        return ['authorize' => $fill($def['authorize']), 'token' => $fill($def['token']), 'userinfo' => $def['userinfo'], 'issuers' => $issuers];
    }

    /** @return array{authorize: string, token: string, userinfo: string, issuers: list<string>} */
    private function discover(string $issuer): array
    {
        return Cache::remember("oidc-discovery:{$issuer}", 3600, function () use ($issuer) {
            $res = Http::timeout(10)->acceptJson()->get($issuer.'/.well-known/openid-configuration');
            $doc = $res->json();
            if (! $res->ok() || ! is_array($doc) || ! isset($doc['authorization_endpoint'], $doc['token_endpoint'], $doc['userinfo_endpoint'])) {
                throw new \RuntimeException('The single sign-on provider did not describe itself.');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $field) {
                if (! str_starts_with((string) $doc[$field], 'https://') && ! str_starts_with((string) $doc[$field], 'http://localhost')) {
                    throw new \RuntimeException('The single sign-on provider must use https.');
                }
            }

            return ['authorize' => $doc['authorization_endpoint'], 'token' => $doc['token_endpoint'], 'userinfo' => $doc['userinfo_endpoint'], 'issuers' => [rtrim((string) ($doc['issuer'] ?? $issuer), '/')]];
        });
    }

    private function get(string $key, string $field): ?string
    {
        return PlatformSetting::get("social.{$key}.{$field}");
    }

    private function put(string $key, string $field, ?string $value): void
    {
        PlatformSetting::put("social.{$key}.{$field}", $value);
    }
}
