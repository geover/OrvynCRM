<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform-wide settings (always the central database, whatever company is open). Currently the look of the sign-in,
 * company chooser and password pages: companies cannot change these, whatever they set for themselves.
 */
class PlatformSetting extends Model
{
    use CentralConnection;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var array<string, ?string>|null per request */
    private static ?array $all = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::$all ??= static::query()->pluck('value', 'key')->all();

        return array_key_exists($key, self::$all) ? self::$all[$key] : $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::$all = null;
    }

    public static function forgetCache(): void
    {
        self::$all = null;
    }
}
