<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\Passkey;
use App\Models\PlatformSetting;
use App\Services\Auth\SignInSecurity;
use App\Services\Auth\TwoFactor;
use App\Support\Cbor;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** Passkeys, checked against a software authenticator that makes real keys and real signatures (what a phone or laptop does). */
class PasskeyTest extends TenantTestCase
{
    private const PASSWORD = 'Owner-Passw0rd-1';

    /** @var resource|\OpenSSLAsymmetricKey */
    private $key;

    private string $credentialId;

    private int $counter = 0;

    private string $origin;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        PlatformSetting::forgetCache();
        app(SignInSecurity::class)->savePasskeys(true);
        PlatformSetting::forgetCache();
        $this->origin = rtrim((string) config('app.url'), '/');
        $this->host = (string) parse_url($this->origin, PHP_URL_HOST);
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->credentialId = random_bytes(32);
    }

    // ---- a tiny software authenticator

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function cbor(mixed $v): string
    {
        $head = fn (int $major, int $n) => $n < 24 ? chr(($major << 5) | $n) : ($n < 256 ? chr(($major << 5) | 24).chr($n) : chr(($major << 5) | 25).pack('n', $n));
        if (is_int($v)) {
            return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        }
        if (is_array($v)) {
            $out = $head(5, count($v));
            foreach ($v as $k => $x) {
                $out .= $this->cbor($k).$this->cbor($x);
            }

            return $out;
        }
        if (str_starts_with((string) $v, "\x00BIN")) {
            $raw = substr($v, 4);

            return $head(2, strlen($raw)).$raw;
        }

        return $head(3, strlen((string) $v)).$v;
    }

    private function bin(string $raw): string
    {
        return "\x00BIN".$raw;
    }

    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return $this->cbor([1 => 2, 3 => -7, -1 => 1, -2 => $this->bin(str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT)), -3 => $this->bin(str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT))]);
    }

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return (string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? $this->origin, 'crossOrigin' => false]);
    }

    /** What the device hands back when adding a passkey. */
    private function attestation(string $challenge, int $flags = 0x45, ?string $rpId = null, ?string $origin = null, ?string $type = null): array
    {
        $rpId ??= $this->host;
        $authData = hash('sha256', $rpId, true).chr($flags).pack('N', 0).str_repeat("\x00", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();
        $object = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => $this->bin($authData)]);

        return ['id' => $this->b64($this->credentialId), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => $this->b64($this->clientData($type ?? 'webauthn.create', $challenge, $origin)), 'attestationObject' => $this->b64($object), 'transports' => ['internal'],
        ]];
    }

    /** What the device hands back when signing in. */
    private function assertion(string $challenge, int $flags = 0x05, ?int $count = null, ?string $origin = null, bool $tamper = false): array
    {
        $count ??= ++$this->counter;
        $authData = hash('sha256', $this->host, true).chr($flags).pack('N', $count);
        $clientData = $this->clientData('webauthn.get', $challenge, $origin);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
        if ($tamper) {
            $signature[10] = chr(ord($signature[10]) ^ 1);
        }

        return ['id' => $this->b64($this->credentialId), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => $this->b64($clientData), 'authenticatorData' => $this->b64($authData), 'signature' => $this->b64($signature),
        ]];
    }

    private function signInPassword(): void
    {
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD]);
    }

    /** Adds the passkey for the signed-in owner, the way the security page does. */
    private function addPasskey(string $name = 'Laptop'): void
    {
        $options = $this->postJson('/account/security/passkeys/options')->assertOk()->json();
        $this->postJson('/account/security/passkeys', ['name' => $name] + $this->attestation($options['challenge']))->assertCreated();
    }

    public function test_the_cbor_reader_decodes_what_a_passkey_sends_and_refuses_the_rest(): void
    {
        $this->assertSame([1 => 2, -1 => 'ab', 'x' => [5, -3]], Cbor::decode($this->cbor([1 => 2, -1 => 'ab', 'x' => [0 => 5, 1 => -3]])));
        $this->expectException(\InvalidArgumentException::class);
        Cbor::decode("\xa1\x01");                                         // a map that ends before its value
    }

    public function test_passkeys_are_off_until_the_platform_turns_them_on(): void
    {
        $this->company('Acme', 'owner@acme.test');
        app(SignInSecurity::class)->savePasskeys(false);
        PlatformSetting::forgetCache();

        $this->postJson('/passkeys/login/options')->assertNotFound();
        $this->get('/login')->assertInertia(fn ($p) => $p->where('passkeys', false));
        $this->signInPassword();
        $this->postJson('/account/security/passkeys/options')->assertNotFound();
    }

    public function test_adding_a_passkey_checks_the_challenge_origin_site_and_that_the_person_was_verified(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->signInPassword();
        $options = $this->postJson('/account/security/passkeys/options')->assertOk()->json();
        $this->assertSame($this->host, $options['rp']['id']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('none', $options['attestation']);

        foreach (['wrong challenge', 'another origin', 'another site', 'not verified (no fingerprint or PIN)', 'a sign-in made into a registration'] as $why) {
            $options = $this->postJson('/account/security/passkeys/options')->json();             // each try needs a fresh challenge
            $credential = match ($why) {
                'wrong challenge' => $this->attestation('forged-challenge'),
                'another origin' => $this->attestation($options['challenge'], origin: 'https://evil.test'),
                'another site' => $this->attestation($options['challenge'], rpId: 'evil.test'),
                'not verified (no fingerprint or PIN)' => $this->attestation($options['challenge'], flags: 0x41),
                default => $this->attestation($options['challenge'], type: 'webauthn.get'),
            };
            $this->postJson('/account/security/passkeys', $credential)->assertStatus(422);
            $this->assertSame(0, Passkey::count(), $why);
        }

        $options = $this->postJson('/account/security/passkeys/options')->json();
        $this->postJson('/account/security/passkeys', ['name' => 'My laptop'] + $this->attestation($options['challenge']))->assertCreated();
        $passkey = Passkey::firstOrFail();
        $this->assertSame('My laptop', $passkey->name);
        $this->assertSame($this->identity('owner@acme.test')->id, $passkey->account_user_id);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $passkey->getAttributes()['public_key']);

        // the same challenge cannot be used twice, and the same passkey cannot be added twice
        $this->postJson('/account/security/passkeys', $this->attestation($options['challenge']))->assertStatus(422);
        $again = $this->postJson('/account/security/passkeys/options')->json();
        $this->postJson('/account/security/passkeys', $this->attestation($again['challenge']))->assertStatus(422);
        $this->assertSame(1, Passkey::count());
        $this->assertSame([$passkey->credential_id], collect($this->postJson('/account/security/passkeys/options')->json('excludeCredentials'))->pluck('id')->all());
    }

    public function test_signing_in_with_a_passkey_needs_no_password_and_no_code_even_with_two_step_on(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->signInPassword();
        $this->addPasskey();
        $secret = app(TwoFactor::class)->newSecret();
        app(TwoFactor::class)->confirm($this->identity('owner@acme.test'), $secret, app(TwoFactor::class)->code($secret, intdiv(time(), 30)));
        $this->post('/logout');
        $this->assertGuest();

        $options = $this->postJson('/passkeys/login/options')->assertOk()->json();
        $this->assertSame([], $options['allowCredentials']);                       // the device offers its own: nothing here says who has an account
        $this->postJson('/passkeys/login', $this->assertion($options['challenge']))->assertOk()->assertJsonPath('redirect', url('/'));
        $this->assertAuthenticated();
        $this->assertSame($acme->id, session('tenant_id'));
        $this->assertNotNull(Passkey::first()->last_used_at);
        $this->assertSame(1, Passkey::first()->sign_count);
    }

    public function test_a_passkey_sign_in_refuses_forgeries_replays_copies_and_suspended_people(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->signInPassword();
        $this->addPasskey();
        $this->post('/logout');

        $cases = [
            'a bad signature' => fn ($c) => $this->assertion($c, tamper: true),
            'another origin' => fn ($c) => $this->assertion($c, origin: 'https://evil.test'),
            'not verified' => fn ($c) => $this->assertion($c, flags: 0x01),
            'wrong challenge' => fn ($c) => $this->assertion('forged'),
        ];
        foreach ($cases as $why => $make) {
            $challenge = $this->postJson('/passkeys/login/options')->json('challenge');
            $this->postJson('/passkeys/login', $make($challenge))->assertStatus(422);
            $this->assertGuest();
        }

        $challenge = $this->postJson('/passkeys/login/options')->json('challenge');
        $good = $this->assertion($challenge, count: 5);
        $this->postJson('/passkeys/login', $good)->assertOk();
        $this->post('/logout');
        $this->postJson('/passkeys/login', $good)->assertStatus(422);              // the challenge was used up: a recording of the sign-in is worthless
        $this->assertGuest();

        $challenge = $this->postJson('/passkeys/login/options')->json('challenge');
        $this->postJson('/passkeys/login', $this->assertion($challenge, count: 5))->assertStatus(422);       // the counter did not go up: a copy
        $this->assertGuest();

        $challenge = $this->postJson('/passkeys/login/options')->json('challenge');
        $this->identity('owner@acme.test')->update(['status' => 'suspended']);
        $this->postJson('/passkeys/login', $this->assertion($challenge, count: 9))->assertStatus(422);
        $this->assertGuest();

        $this->postJson('/passkeys/login', ['id' => 'unknown', 'response' => ['clientDataJSON' => 'x']])->assertStatus(422);
    }

    public function test_removing_a_passkey_needs_the_password_and_only_works_on_your_own(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $this->signInPassword();
        $this->addPasskey();
        $passkey = Passkey::firstOrFail();

        $this->deleteJson("/account/security/passkeys/{$passkey->id}", ['password' => 'wrong'])->assertStatus(422);
        $this->assertSame(1, Passkey::count());

        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@beta.test', 'password' => self::PASSWORD]);
        $this->deleteJson("/account/security/passkeys/{$passkey->id}", ['password' => self::PASSWORD])->assertNotFound();
        $this->post('/logout');

        $this->signInPassword();
        $this->deleteJson("/account/security/passkeys/{$passkey->id}", ['password' => self::PASSWORD])->assertNoContent();
        $this->assertSame(0, Passkey::count());
    }

    public function test_a_passkey_satisfies_a_required_second_step_and_the_security_page_lists_them(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->signInPassword();
        app(SignInSecurity::class)->savePolicy('everyone');
        $this->get('/contacts')->assertRedirect('/security');
        $this->postJson('/account/security/passkeys/options');
        $this->addPasskey();
        $this->get('/contacts')->assertOk();

        $props = $this->get('/security')->viewData('page')['props']['security'];
        $this->assertTrue($props['passkeys_enabled']);
        $this->assertCount(1, $props['passkeys']);
        $this->assertSame('Laptop', $props['passkeys'][0]['name']);
        $this->assertArrayNotHasKey('public_key', $props['passkeys'][0]);
        $this->assertNotNull(AccountUser::first());
    }
}
