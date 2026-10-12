<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The e-mail server. It starts as whatever .env says (MAIL_HOST, MAIL_PORT ...); a platform administrator can switch on
 * "use my own server" on the Settings page and the values saved there replace it for every e-mail the application sends.
 * Applied lazily, the first time a mailer is needed (see AppServiceProvider).
 */
class MailSettings
{
    /** @var array<string, mixed>|null what .env gave, remembered before an override replaces it */
    private static ?array $env = null;

    /** @return array{host: ?string, port: ?int, username: ?string, scheme: string, from_address: ?string, from_name: ?string, mailer: string} */
    public function envDefaults(): array
    {
        return self::$env ??= [
            'host' => config('mail.mailers.smtp.host'), 'port' => (int) config('mail.mailers.smtp.port'),
            'username' => config('mail.mailers.smtp.username'), 'scheme' => config('mail.mailers.smtp.scheme') ?: 'smtp',
            'from_address' => config('mail.from.address'), 'from_name' => config('mail.from.name'), 'mailer' => (string) config('mail.default'),
        ];
    }

    /** @return array{enabled: bool, host: string, port: int|null, username: string, password_set: bool, scheme: string, from_address: string, from_name: string} never the password itself */
    public function current(): array
    {
        return [
            'enabled' => PlatformSetting::get('mail.enabled') === '1',
            'host' => (string) PlatformSetting::get('mail.host'), 'port' => PlatformSetting::get('mail.port') ? (int) PlatformSetting::get('mail.port') : null,
            'username' => (string) PlatformSetting::get('mail.username'), 'password_set' => (bool) PlatformSetting::get('mail.password'),
            'scheme' => PlatformSetting::get('mail.scheme') === 'smtps' ? 'smtps' : 'smtp',
            'from_address' => (string) PlatformSetting::get('mail.from_address'), 'from_name' => (string) PlatformSetting::get('mail.from_name'),
        ];
    }

    /**
     * An empty password keeps the saved one; `clear_password` removes it.
     *
     * @param  array<string, mixed>  $d
     */
    public function save(array $d): void
    {
        PlatformSetting::put('mail.enabled', ! empty($d['enabled']) ? '1' : '0');
        foreach (['host', 'port', 'username', 'from_address', 'from_name'] as $key) {
            PlatformSetting::put("mail.{$key}", ($d[$key] ?? null) === null || $d[$key] === '' ? null : (string) $d[$key]);
        }
        PlatformSetting::put('mail.scheme', ($d['scheme'] ?? 'smtp') === 'smtps' ? 'smtps' : 'smtp');
        if (! empty($d['password'])) {
            PlatformSetting::put('mail.password', Crypt::encryptString($d['password']));
        }
        if (! empty($d['clear_password'])) {
            PlatformSetting::put('mail.password', null);
        }
    }

    /** Replace the .env mail settings with the saved ones, when the platform switched that on. A missing table or a bad value never stops the app. */
    public function apply(): void
    {
        $this->envDefaults();
        try {
            if (PlatformSetting::get('mail.enabled') !== '1' || ! PlatformSetting::get('mail.host')) {
                return;
            }
            $password = PlatformSetting::get('mail.password');
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => PlatformSetting::get('mail.host'),
                'mail.mailers.smtp.port' => (int) (PlatformSetting::get('mail.port') ?: 587),
                'mail.mailers.smtp.username' => PlatformSetting::get('mail.username') ?: null,
                'mail.mailers.smtp.password' => $password ? Crypt::decryptString($password) : null,
                'mail.mailers.smtp.scheme' => PlatformSetting::get('mail.scheme') === 'smtps' ? 'smtps' : 'smtp',
            ]);
            if ($from = PlatformSetting::get('mail.from_address')) {
                config(['mail.from.address' => $from]);
            }
            if ($name = PlatformSetting::get('mail.from_name')) {
                config(['mail.from.name' => $name]);
            }
        } catch (Throwable) {
            // keep the .env settings
        }
    }
}
