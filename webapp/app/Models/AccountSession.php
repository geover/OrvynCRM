<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** One sign-in of one person (see the migration). */
class AccountSession extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_seen_at' => 'datetime', 'ended_at' => 'datetime', 'two_step' => 'bool'];
    }

    /** Seconds the person was signed in: until they signed out, or until they were last seen. */
    public function seconds(): int
    {
        return max(0, ($this->ended_at ?? $this->last_seen_at)->getTimestamp() - $this->started_at->getTimestamp());
    }
}
