<?php

namespace App\Services\Tenancy;

use App\Models\DataObjectRecord;
use App\Models\ObjectService;
use App\Models\Tenant;
use App\Models\TenantService;
use Carbon\CarbonInterface;

/**
 * The background services every company has, and the platform's switch for each.
 *
 * A switched-off service stops dispatching and processing work for that company only; the data is untouched and
 * work that is already due is picked up when the service is switched on again. The switches live in the central
 * database so the scheduler and every worker can read them without opening the company.
 */
class CompanyServices
{
    /** @var array<string, array{label: string, text: string, schedule: string}> */
    public const CATALOG = [
        'messages' => ['label' => 'Email & SMS sending', 'text' => 'Sends scheduled and queued messages when they fall due.', 'schedule' => 'every minute, when messages are due'],
        'automations' => ['label' => 'Automations', 'text' => 'Evaluates automations: on a schedule and when records change.', 'schedule' => 'every minute / hourly / daily, and on record changes'],
        'task_digest' => ['label' => 'Overdue task digest', 'text' => 'Notifies people about their overdue tasks.', 'schedule' => 'hourly'],
        'keap_sync' => ['label' => 'Keap sync', 'text' => 'Pulls contacts from Keap (only when Keap is connected).', 'schedule' => 'every 15 minutes, full sync weekly'],
        'housekeeping' => ['label' => 'History clean-up', 'text' => 'Prunes old activity history.', 'schedule' => 'weekly'],
    ];

    /** Workers live for an hour: a switch must reach them within seconds, not at their next restart. */
    private const FRESH_FOR = 5;

    /** @var array<string, array{0: int, 1: array<string, TenantService>}> */
    private array $loaded = [];

    /** Is the service on for the open company (or the given one)? Outside a company (single-database tests) it is on. */
    public function enabled(string $service, Tenant|string|null $company = null): bool
    {
        $id = $this->idOf($company);

        return $id === null || ($this->rows($id)[$service]->enabled ?? true);
    }

    /** Services that can also be switched per data object (their work belongs to one). */
    public const PER_OBJECT = ['automations', 'messages', 'task_digest'];

    /** What a service means for one data object (shown next to its switch there). */
    public const OBJECT_TEXT = [
        'automations' => 'Evaluates this data object\'s automations: on a schedule and when its records change.',
        'messages' => 'Holds back the e-mails and SMS this data object\'s automations created. They are sent when you switch it on again.',
        'task_digest' => 'Leaves the tasks this data object\'s automations created out of the overdue-task digest.',
    ];

    /**
     * Is the service on for this data object? Both the company switch and the object's own switch have to be on.
     * Outside a company it is on.
     */
    public function objectEnabled(string $service, ?int $dataObjectId, Tenant|string|null $company = null): bool
    {
        $id = $this->idOf($company);
        if ($id === null || ! $this->enabled($service, $id)) {
            return $id === null;
        }

        return ! $dataObjectId || ObjectService::where('tenant_id', $id)->where('data_object_id', $dataObjectId)->where('service', $service)->where('enabled', false)->doesntExist();
    }

    public function setObject(Tenant|string $company, string $service, int $dataObjectId, bool $on): void
    {
        abort_unless(in_array($service, self::PER_OBJECT, true), 422, 'That service cannot be switched per data object.');
        ObjectService::updateOrCreate(['tenant_id' => $this->idOf($company), 'data_object_id' => $dataObjectId, 'service' => $service], ['enabled' => $on, 'changed_at' => now()]);
    }

    /** @return list<int> the data objects whose given service is switched off for the company */
    public function pausedObjects(Tenant|string $company, string $service): array
    {
        return ObjectService::where('tenant_id', $this->idOf($company))->where('service', $service)->where('enabled', false)->pluck('data_object_id')->all();
    }

    /**
     * Is a queued message held back because the data object of its record has message sending switched off?
     * Messages that no automation made (no record) are never held.
     */
    public function holdsMessage(?int $dataObjectRecordId): bool
    {
        $company = tenant('id');
        if (! $dataObjectRecordId || ! $company) {
            return false;
        }
        $paused = $this->pausedObjects($company, 'messages');

        return $paused !== [] && in_array(DataObjectRecord::whereKey($dataObjectRecordId)->value('data_object_id'), $paused, true);
    }

    /** Remember that the service did something, for the platform page. */
    public function recordRun(string $service, int $count = 1, Tenant|string|null $company = null): void
    {
        if (($id = $this->idOf($company)) === null) {
            return;
        }
        $row = TenantService::firstOrNew(['tenant_id' => $id, 'service' => $service]);
        $row->enabled ??= true;
        $row->fill(['last_run_at' => now(), 'last_count' => $count])->save();
        unset($this->loaded[$id]);
    }

    public function set(Tenant|string $company, string $service, bool $on): void
    {
        abort_unless(isset(self::CATALOG[$service]), 422, 'Unknown service.');
        $id = $this->idOf($company);
        TenantService::updateOrCreate(['tenant_id' => $id, 'service' => $service], ['enabled' => $on, 'changed_at' => now()]);
        unset($this->loaded[$id]);
    }

    /** @return array<string, array{enabled: bool, last_run_at: ?string, last_count: int}> */
    public function states(Tenant|string $company): array
    {
        $rows = $this->rows($this->idOf($company));

        return collect(self::CATALOG)->map(fn ($meta, $key) => [
            'enabled' => $rows[$key]->enabled ?? true,
            'last_run_at' => ($at = $rows[$key]->last_run_at ?? null) instanceof CarbonInterface ? $at->toIso8601String() : null,
            'last_count' => $rows[$key]->last_count ?? 0,
        ])->all();
    }

    /** @return array<string, TenantService> */
    private function rows(string $id): array
    {
        if (! isset($this->loaded[$id]) || time() - $this->loaded[$id][0] > self::FRESH_FOR) {
            $this->loaded[$id] = [time(), TenantService::where('tenant_id', $id)->get()->keyBy('service')->all()];
        }

        return $this->loaded[$id][1];
    }

    private function idOf(Tenant|string|null $company): ?string
    {
        if ($company instanceof Tenant) {
            return (string) $company->getTenantKey();
        }

        return $company ?? (tenant('id') ? (string) tenant('id') : null);
    }
}
