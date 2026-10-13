<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Someone asking for a workspace of their own. Central: it exists before any company does. */
class Signup extends Model
{
    use CentralConnection;

    public const PENDING_EMAIL = 'pending_email';

    public const VERIFIED = 'verified';

    public const PENDING_APPROVAL = 'pending_approval';

    public const APPROVED = 'approved';

    public const COMPLETING = 'completing';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['marketing_opt_in' => 'boolean', 'expires_at' => 'datetime', 'verified_at' => 'datetime', 'decided_at' => 'datetime', 'completed_at' => 'datetime', 'last_sent_at' => 'datetime'];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Can this person set a password and get their workspace now? */
    public function isReady(): bool
    {
        return in_array($this->status, [self::VERIFIED, self::APPROVED], true) && ! $this->isExpired();
    }
}
