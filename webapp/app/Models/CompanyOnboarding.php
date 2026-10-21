<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Where one company is in the onboarding series: the steps it has reached, what it was sent and whether it needs a hand. */
class CompanyOnboarding extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'milestones' => 'array', 'help_reasons' => 'array', 'needs_help' => 'boolean',
            'started_at' => 'datetime', 'last_progress_at' => 'datetime', 'last_sent_at' => 'datetime', 'help_since' => 'datetime', 'help_snoozed_until' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(OnboardingSend::class, 'tenant_id', 'tenant_id');
    }

    /** Whether the platform should look at this company now: flagged, and not set aside as handled. */
    public function asksForHelp(): bool
    {
        return $this->needs_help && ($this->help_snoozed_until === null || $this->help_snoozed_until->isPast());
    }
}
