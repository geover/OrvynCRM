<?php

namespace App\Jobs;

use App\Integrations\Keap\KeapClient;
use App\Models\Contact;
use App\Models\Integration;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Incremental contact sync (replaces services/sync.php, tcards_sync.php, field_sync.php). */
class SyncKeapContacts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 2;

    public function __construct(public bool $full = false)
    {
        $this->onConnection(config('queue.long'))->onQueue('sync');   // long job: its own visibility timeout, see config/queue.php
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('keap-sync'))->expireAfter(3700)->dontRelease()];
    }

    public function handle(KeapClient $keap, CompanyServices $services): void
    {
        if (! $services->enabled('keap_sync')) {
            return;
        }

        $integration = Integration::for('keap');
        if (! $integration) {
            return;
        }

        $started = now();
        $since = $this->full ? null : $integration->last_synced_at?->toIso8601String();

        foreach ($keap->contacts($since) as $page) {
            $rows = collect($page)->map(fn (array $c) => $this->map($c))->all();
            // One bulk upsert per page instead of one SELECT+INSERT/UPDATE per contact.
            if ($rows) {
                Contact::upsert($rows, ['legacy_id'], array_diff(array_keys($rows[0]), ['legacy_id', 'created_at']));
            }
        }

        $integration->update(['last_synced_at' => $started]);
    }

    private function map(array $c): array
    {
        $addr = $c['addresses'][0] ?? [];

        return [
            'legacy_id' => $c['id'],
            'first_name' => $c['given_name'] ?? null,
            'last_name' => $c['family_name'] ?? null,
            'email' => isset($c['email_addresses'][0]['email']) ? mb_strtolower(trim($c['email_addresses'][0]['email'])) : null,   // bulk upsert bypasses model mutators
            'phone' => $c['phone_numbers'][0]['number'] ?? null,
            'company_name' => $c['company']['company_name'] ?? null,
            'job_title' => $c['job_title'] ?? null,
            'addresses' => $addr ? json_encode([[
                'type' => $addr['field'] ?? 'BILLING', 'street1' => $addr['line1'] ?? null, 'street2' => $addr['line2'] ?? null,
                'city' => $addr['locality'] ?? null, 'state' => $addr['region'] ?? null,
                'zip' => $addr['postal_code'] ?? null, 'country' => $addr['country_code'] ?? null,
            ]]) : null,
            'source' => 'keap',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
