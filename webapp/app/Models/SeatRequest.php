<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A company's request for more users, more data objects, more automations for one data object, or more texts per month. Handled by a platform
 * administrator (billing is not part of this yet). See App\Services\Tenancy\LimitRequests.
 */
class SeatRequest extends Model
{
    use CentralConnection;

    public const USERS = 'users';

    public const DATA_OBJECTS = 'data_objects';

    public const AUTOMATIONS = 'automations';

    public const TEXTS = 'texts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')->whereNull('archived_at');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** What is asked for, in words: "more users", "more data objects", "more automations for “Deals”". */
    public function subject(): string
    {
        return match ($this->kind) {
            self::DATA_OBJECTS => 'more data objects',
            self::AUTOMATIONS => "more automations for “{$this->data_object_name}”",
            self::TEXTS => 'more texts per month',
            default => 'more users',
        };
    }

    /** One of the thing asked for: "user", "data object" or "automation". */
    public function unit(): string
    {
        return match ($this->kind) {
            self::DATA_OBJECTS => 'data object',
            self::AUTOMATIONS => 'automation',
            self::TEXTS => 'text',
            default => 'user',
        };
    }
}
