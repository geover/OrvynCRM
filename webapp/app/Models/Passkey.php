<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** A device credential (fingerprint, face, a PIN or a security key) that signs one person in without a password. */
class Passkey extends Model
{
    use CentralConnection;

    protected $guarded = [];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'backed_up' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountUser::class, 'account_user_id');
    }
}
