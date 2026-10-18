<?php

namespace App\Services\Auth;

use App\Models\AccountUser;
use App\Models\Passkey;
use App\Support\Cbor;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Passkeys: signing in with a fingerprint, a face, a device PIN or a security key (the WebAuthn standard), no password and no code to type.
 * The device holds a private key and only ever gives out signatures, so there is nothing to phish or to leak from here: the database keeps
 * public keys. Every check the standard asks for is made: a fresh one-time challenge, the exact origin and relying-party id, that the person
 * was present and verified (a fingerprint or PIN, not just a touch), the signature, and a counter that must not go backwards.
 */
class WebAuthn
{
    private const SESSION = 'webauthn.challenge';

    private const FLAG_USER_PRESENT = 0x01;

    private const FLAG_USER_VERIFIED = 0x04;

    private const FLAG_BACKUP_ELIGIBLE = 0x08;

    private const FLAG_BACKED_UP = 0x10;

    private const FLAG_ATTESTED = 0x40;

    private const ALG_ES256 = -7;

    private const ALG_RS256 = -257;

    /** @return array<string, mixed> what navigator.credentials.create() needs */
    public function registrationOptions(Request $request, AccountUser $user, string $rpName): array
    {
        return [
            'challenge' => $this->challenge($request, 'create'),
            'rp' => ['id' => $request->getHost(), 'name' => $rpName],
            'user' => ['id' => $this->b64(hash_hmac('sha256', 'passkey-user-'.$user->id, (string) config('app.key'), true)), 'name' => $user->email, 'displayName' => $user->name],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ALG_ES256], ['type' => 'public-key', 'alg' => self::ALG_RS256]],
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => Passkey::where('account_user_id', $user->id)->pluck('credential_id')->map(fn ($id) => ['type' => 'public-key', 'id' => $id])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $credential  what the browser made
     * @return array{credential_id: string, public_key: string, sign_count: int, backed_up: bool, transports: ?string}
     *
     * @throws PasskeyFailed
     */
    public function verifyRegistration(Request $request, array $credential): array
    {
        $response = (array) ($credential['response'] ?? []);
        $this->checkClientData($request, (string) ($response['clientDataJSON'] ?? ''), 'webauthn.create');

        try {
            $attestation = Cbor::decode($this->unb64((string) ($response['attestationObject'] ?? '')));
            $authData = (string) ($attestation['authData'] ?? '');
            $parsed = $this->parseAuthData($authData, $request);
        } catch (InvalidArgumentException) {
            throw new PasskeyFailed('That passkey could not be read.');
        }
        if (($parsed['flags'] & self::FLAG_ATTESTED) === 0 || $parsed['credential_id'] === null) {
            throw new PasskeyFailed('That passkey did not include a key.');
        }
        if (($parsed['flags'] & self::FLAG_USER_VERIFIED) === 0) {
            throw new PasskeyFailed('Your device did not check who you are (fingerprint, face or PIN). Turn on its screen lock and try again.');
        }
        if (! hash_equals($this->b64($parsed['credential_id']), (string) ($credential['id'] ?? ''))) {
            throw new PasskeyFailed('That passkey did not check out.');
        }
        if (Passkey::where('credential_id', $this->b64($parsed['credential_id']))->exists()) {
            throw new PasskeyFailed('That passkey is already added.');
        }

        return [
            'credential_id' => $this->b64($parsed['credential_id']),
            'public_key' => $this->pem((array) $parsed['key']),
            'sign_count' => $parsed['count'],
            'backed_up' => ($parsed['flags'] & self::FLAG_BACKED_UP) !== 0,
            'transports' => isset($response['transports']) && is_array($response['transports']) ? implode(',', array_slice(array_filter($response['transports'], 'is_string'), 0, 6)) : null,
        ];
    }

    /** @return array<string, mixed> what navigator.credentials.get() needs: no list of keys, so the device offers the passkeys it holds for this site */
    public function authenticationOptions(Request $request): array
    {
        return ['challenge' => $this->challenge($request, 'get'), 'rpId' => $request->getHost(), 'timeout' => 120000, 'userVerification' => 'required', 'allowCredentials' => []];
    }

    /**
     * Checks the signature and returns the passkey that made it (its counter and last-used time are updated).
     *
     * @param  array<string, mixed>  $assertion
     *
     * @throws PasskeyFailed
     */
    public function verifyAuthentication(Request $request, array $assertion): Passkey
    {
        $response = (array) ($assertion['response'] ?? []);
        $clientData = $this->checkClientData($request, (string) ($response['clientDataJSON'] ?? ''), 'webauthn.get');
        $passkey = Passkey::where('credential_id', (string) ($assertion['id'] ?? ''))->first();
        if (! $passkey) {
            throw new PasskeyFailed('That passkey is not known here. Sign in another way, then add it again.');
        }

        try {
            $authData = $this->unb64((string) ($response['authenticatorData'] ?? ''));
            $parsed = $this->parseAuthData($authData, $request);
        } catch (InvalidArgumentException) {
            throw new PasskeyFailed('That passkey could not be read.');
        }
        if (($parsed['flags'] & self::FLAG_USER_VERIFIED) === 0) {
            throw new PasskeyFailed('Your device did not check who you are (fingerprint, face or PIN).');
        }
        $signed = $authData.hash('sha256', $this->unb64((string) $response['clientDataJSON']), true);
        $valid = openssl_verify($signed, $this->unb64((string) ($response['signature'] ?? '')), $passkey->public_key, OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            throw new PasskeyFailed('That passkey did not check out.');
        }
        // a counter that does not go up means the passkey was copied (synced passkeys always report 0, which is fine)
        if (($parsed['count'] > 0 || $passkey->sign_count > 0) && $parsed['count'] <= $passkey->sign_count) {
            throw new PasskeyFailed('That passkey looks copied, so it was refused.');
        }
        $handle = (string) ($response['userHandle'] ?? '');
        if ($handle !== '' && ! hash_equals($this->b64(hash_hmac('sha256', 'passkey-user-'.$passkey->account_user_id, (string) config('app.key'), true)), $handle)) {
            throw new PasskeyFailed('That passkey belongs to somebody else.');
        }
        unset($clientData);
        $passkey->forceFill(['sign_count' => $parsed['count'], 'last_used_at' => now(), 'backed_up' => ($parsed['flags'] & self::FLAG_BACKED_UP) !== 0])->save();

        return $passkey;
    }

    private function challenge(Request $request, string $type): string
    {
        $challenge = $this->b64(random_bytes(32));
        $request->session()->put(self::SESSION, ['value' => $challenge, 'type' => $type, 'until' => time() + 300]);

        return $challenge;
    }

    /**
     * The browser's description of the ceremony: the one-time challenge we gave, and exactly which site it was made for.
     *
     * @return array<string, mixed>
     */
    private function checkClientData(Request $request, string $clientDataJson, string $type): array
    {
        $pending = $request->session()->pull(self::SESSION);
        $data = json_decode($this->unb64($clientDataJson), true);
        if (! is_array($pending) || $pending['until'] < time() || ! is_array($data)) {
            throw new PasskeyFailed('That took too long. Try again.');
        }
        if (($data['type'] ?? null) !== $type || ! hash_equals($pending['value'], (string) ($data['challenge'] ?? '')) || ($pending['type'] === 'create') !== ($type === 'webauthn.create')) {
            throw new PasskeyFailed('That passkey did not check out. Try again.');
        }
        if (! hash_equals($request->getSchemeAndHttpHost(), (string) ($data['origin'] ?? '')) || ($data['crossOrigin'] ?? false) === true) {
            throw new PasskeyFailed('That passkey was made for another website.');
        }

        return $data;
    }

    /** @return array{flags: int, count: int, credential_id: ?string, key: ?array<int|string, mixed>} */
    private function parseAuthData(string $a, Request $request): array
    {
        if (strlen($a) < 37) {
            throw new InvalidArgumentException('Too short.');
        }
        if (! hash_equals(substr($a, 0, 32), hash('sha256', $request->getHost(), true))) {
            throw new PasskeyFailed('That passkey was made for another website.');
        }
        $flags = ord($a[32]);
        if (($flags & self::FLAG_USER_PRESENT) === 0) {
            throw new PasskeyFailed('Your device did not confirm that you were there.');
        }
        $out = ['flags' => $flags, 'count' => (int) unpack('N', substr($a, 33, 4))[1], 'credential_id' => null, 'key' => null];
        if (($flags & self::FLAG_ATTESTED) !== 0) {
            if (strlen($a) < 55) {
                throw new InvalidArgumentException('Too short.');
            }
            $idLength = (int) unpack('n', substr($a, 53, 2))[1];
            $out['credential_id'] = substr($a, 55, $idLength);
            [$out['key']] = Cbor::decodeFirst($a, 55 + $idLength);
        }

        return $out;
    }

    /** The public key in the standard PEM form PHP's OpenSSL reads. @param  array<int|string, mixed>  $cose */
    private function pem(array $cose): string
    {
        $alg = $cose[3] ?? null;
        if (($cose[1] ?? null) === 2 && $alg === self::ALG_ES256 && ($cose[-1] ?? null) === 1 && strlen((string) ($cose[-2] ?? '')) === 32 && strlen((string) ($cose[-3] ?? '')) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$cose[-2].$cose[-3];
        } elseif (($cose[1] ?? null) === 3 && $alg === self::ALG_RS256 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
            $rsaKey = $this->asn1(0x30, $this->asn1(0x02, $this->unsigned($cose[-1])).$this->asn1(0x02, $this->unsigned($cose[-2])));
            $der = $this->asn1(0x30, hex2bin('300d06092a864886f70d0101010500').$this->asn1(0x03, "\x00".$rsaKey));
        } else {
            throw new PasskeyFailed('That kind of passkey is not supported.');
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function asn1(int $tag, string $content): string
    {
        $n = strlen($content);
        $length = $n < 128 ? chr($n) : (($n < 256) ? "\x81".chr($n) : "\x82".chr($n >> 8).chr($n & 0xFF));

        return chr($tag).$length.$content;
    }

    /** A DER INTEGER is signed: a leading 1 bit would read as negative. */
    private function unsigned(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        return (ord($bytes[0] ?? "\x00") & 0x80) ? "\x00".$bytes : $bytes;
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }
}
