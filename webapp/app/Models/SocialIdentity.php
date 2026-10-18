<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** One person's account at one identity provider (Google, Microsoft, GitHub or the platform's own OpenID Connect provider). */
class SocialIdentity extends Model
{
    use CentralConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountUser::class, 'account_user_id');
    }
}
