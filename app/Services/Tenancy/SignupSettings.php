<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;

/** Whether the platform lets anybody create a workspace themselves (Platform > Sign-ups). Closed until the platform opens it. */
class SignupSettings
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const APPROVAL = 'approval';

    public const MODES = [self::CLOSED, self::OPEN, self::APPROVAL];

    /** closed: nobody can sign up. open: confirm your e-mail and you are in. approval: a platform administrator accepts each one first. */
    public function mode(): string
    {
        $mode = PlatformSetting::get('signup.mode');

        return in_array($mode, self::MODES, true) ? $mode : self::CLOSED;
    }

    public function accepting(): bool
    {
        return $this->mode() !== self::CLOSED;
    }

    public function save(string $mode): void
    {
        abort_unless(in_array($mode, self::MODES, true), 422, 'Unknown sign-up mode.');
        PlatformSetting::put('signup.mode', $mode);
    }
}
