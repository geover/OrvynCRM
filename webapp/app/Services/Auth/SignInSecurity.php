<?php

namespace App\Services\Auth;

use App\Models\AccountUser;
use App\Models\Passkey;
use App\Models\PlatformSetting;

/**
 * The platform's rules for how people sign in. Today: who must use two-step verification. A person who must, and has not set it up, can
 * do nothing but set it up until they have (see RequireTwoFactor).
 */
class SignInSecurity
{
    public const POLICIES = ['optional', 'platform_admins', 'admins', 'everyone'];

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function policy(): string
    {
        $saved = (string) PlatformSetting::get('security.two_factor', 'optional');

        return in_array($saved, self::POLICIES, true) ? $saved : 'optional';
    }

    public function savePolicy(string $policy): void
    {
        PlatformSetting::put('security.two_factor', in_array($policy, self::POLICIES, true) ? $policy : 'optional');
    }

    /** Passkeys (a fingerprint, face or PIN on a device) are offered only while the platform has them on. */
    public function passkeysEnabled(): bool
    {
        return PlatformSetting::get('security.passkeys') === '1';
    }

    public function savePasskeys(bool $enabled): void
    {
        PlatformSetting::put('security.passkeys', $enabled ? '1' : '0');
    }

    /**
     * Must this person use two-step verification? A company administrator is told by the role in the company they have open, so
     * `$companyAdmin` is null while no company is open (the platform area, the company chooser).
     */
    public function requiresTwoFactor(AccountUser $identity, ?bool $companyAdmin): bool
    {
        return match ($this->policy()) {
            'everyone' => true,
            'admins' => $identity->is_platform_admin || $companyAdmin === true,
            'platform_admins' => $identity->is_platform_admin,
            default => false,
        };
    }

    /** A passkey is a password and a second step in one, so it counts. */
    public function hasPasskey(AccountUser $identity): bool
    {
        return $this->passkeysEnabled() && Passkey::where('account_user_id', $identity->id)->exists();
    }

    /** Done: they use it, or they are not required to. */
    public function satisfied(AccountUser $identity, ?bool $companyAdmin): bool
    {
        return $this->twoFactor->isEnabled($identity) || $this->hasPasskey($identity) || ! $this->requiresTwoFactor($identity, $companyAdmin);
    }

    /** @return array{enrolled: int, total: int} how many active people use it (for the platform's setting page) */
    public function adoption(): array
    {
        $active = AccountUser::where('status', 'active');

        return ['enrolled' => (clone $active)->whereNotNull('two_factor_confirmed_at')->count(), 'total' => $active->count()];
    }
}
