<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central lookup for requests that carry no session (embedded forms, shared price lists, webhooks):
 * the unguessable key in the URL tells us which company's database to open. URLs stay identical for every company.
 */
class PublicLink extends Model
{
    use CentralConnection;

    public const FORM = 'form';

    public const PRICE_LIST = 'price_list';

    public const WEBHOOK = 'webhook';

    protected $table = 'public_links';

    protected $guarded = ['id'];

    /** Register (or re-point) a key for the company that is currently active. */
    public static function register(string $type, string $key, ?string $tenantId = null): void
    {
        $tenantId ??= tenant('id');
        if ($tenantId === null) {
            return;      // single-database mode (tests): nothing to look up
        }
        static::updateOrCreate(['key' => $key], ['type' => $type, 'tenant_id' => $tenantId]);
    }

    public static function forget(string $key): void
    {
        if (tenant('id') !== null) {
            static::where('key', $key)->delete();
        }
    }

    public static function resolve(string $type, string $key): ?Tenant
    {
        $link = static::where('type', $type)->where('key', $key)->first();

        return $link ? Tenant::find($link->tenant_id) : null;
    }
}
