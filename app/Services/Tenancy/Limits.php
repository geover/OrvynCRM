<?php

namespace App\Services\Tenancy;

use App\Models\Automation;
use App\Models\DataObject;
use App\Models\ObjectAutomationLimit;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * What a company may build: a number of data objects, and a number of automations per data object (a company-wide
 * default, raised for single objects when the platform approves a request). Works inside the OPEN company; without one
 * (the single-database test mode) nothing is limited. Automations that belong to no data object are not limited.
 */
class Limits
{
    public function dataObjectsUsed(): int
    {
        return DataObject::count();
    }

    public function dataObjectsMax(?Tenant $company = null): ?int
    {
        $id = $company?->getTenantKey() ?? tenant('id');

        return $id ? (int) Tenant::whereKey($id)->value('max_data_objects') : null;
    }

    /** The company-wide automations-per-object default. */
    public function automationsDefault(?Tenant $company = null): ?int
    {
        $id = $company?->getTenantKey() ?? tenant('id');

        return $id ? (int) Tenant::whereKey($id)->value('max_automations_per_object') : null;
    }

    /** The limit of one data object: its own override, else the company default. */
    public function automationsMax(int $dataObjectId, ?Tenant $company = null): ?int
    {
        $id = $company?->getTenantKey() ?? tenant('id');
        if (! $id) {
            return null;
        }

        return ObjectAutomationLimit::where('tenant_id', $id)->where('data_object_id', $dataObjectId)->value('max_automations') ?? $this->automationsDefault($company);
    }

    public function automationsUsed(int $dataObjectId): int
    {
        return Automation::where('data_object_id', $dataObjectId)->count();
    }

    /** @return array{used: int, max: int|null, left: int|null, full: bool} */
    public function dataObjects(): array
    {
        return $this->shape($this->dataObjectsUsed(), $this->dataObjectsMax());
    }

    /** @return list<array{id: int, name: string, used: int, max: int|null, left: int|null, full: bool, custom: bool}> every data object with its automation numbers */
    public function objects(): array
    {
        $tenantId = tenant('id');
        $overrides = $tenantId ? ObjectAutomationLimit::where('tenant_id', $tenantId)->pluck('max_automations', 'data_object_id') : collect();
        $default = $this->automationsDefault();
        $counts = Automation::whereNotNull('data_object_id')->selectRaw('data_object_id, count(*) as n')->groupBy('data_object_id')->pluck('n', 'data_object_id');

        return DataObject::orderBy('name')->get(['id', 'name'])->map(fn (DataObject $o) => [
            'id' => $o->id, 'name' => $o->name, 'custom' => $overrides->has($o->id),
        ] + $this->shape((int) ($counts[$o->id] ?? 0), $overrides[$o->id] ?? $default))->all();
    }

    /**
     * @throws ValidationException
     */
    public function ensureDataObjectRoom(string $field = 'name'): void
    {
        $max = $this->dataObjectsMax();
        if ($max !== null && $this->dataObjectsUsed() >= $max) {
            throw ValidationException::withMessages([$field => "This company has reached its limit of {$max} data objects. Ask your platform administrator for more."]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function ensureAutomationRoom(?int $dataObjectId, string $field = 'data_object_id'): void
    {
        if (! $dataObjectId) {
            return;
        }
        $max = $this->automationsMax($dataObjectId);
        if ($max !== null && $this->automationsUsed($dataObjectId) >= $max) {
            $name = DataObject::whereKey($dataObjectId)->value('name');
            throw ValidationException::withMessages([$field => "\"{$name}\" has reached its limit of {$max} automations. Ask your platform administrator for more."]);
        }
    }

    /** @return array{used: int, max: int|null, left: int|null, full: bool} */
    private function shape(int $used, ?int $max): array
    {
        return ['used' => $used, 'max' => $max, 'left' => $max === null ? null : max(0, $max - $used), 'full' => $max !== null && $used >= $max];
    }
}
