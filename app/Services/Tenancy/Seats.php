<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantStat;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The people limit of a company. A seat is an ACTIVE person of the company; platform administrators' support profiles
 * never count, and neither do suspended people (suspending frees the seat, reactivating needs one).
 * Without a company open (the single-database test mode) there is no limit.
 */
class Seats
{
    /** Active people of the OPEN company. */
    public function used(): int
    {
        return User::listed()->where('status', 'active')->count();
    }

    public function usedFor(Tenant $company): int
    {
        return $company->run(fn () => $this->used());
    }

    /** The limit of the open company (null: none, e.g. single-database mode). */
    public function limit(): ?int
    {
        $id = tenant('id');

        return $id ? (int) Tenant::whereKey($id)->value('max_users') : null;
    }

    /** @return array{used: int, max: int|null, left: int|null, full: bool} for the open company */
    public function summary(): array
    {
        $used = $this->used();
        $max = $this->limit();

        return ['used' => $used, 'max' => $max, 'left' => $max === null ? null : max(0, $max - $used), 'full' => $max !== null && $used >= $max];
    }

    /**
     * Refuse when adding $adding more active people would pass the limit of the open company.
     *
     * @throws ValidationException
     */
    public function ensureRoom(int $adding = 1, string $field = 'email'): void
    {
        $max = $this->limit();
        if ($max === null) {
            return;
        }
        $used = $this->used();
        if ($used + $adding > $max) {
            throw ValidationException::withMessages([$field => "This company has reached its limit of {$max} users ({$used} of {$max} in use). Ask your platform administrator for more."]);
        }
    }

    /** Keep the dashboard's user count current right after people are added, removed or (re)activated. */
    public function sync(): void
    {
        if ($id = tenant('id')) {
            TenantStat::updateOrCreate(['tenant_id' => $id], ['users' => $this->used()]);
        }
    }
}
