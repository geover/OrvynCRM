<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A company's subscription as Stripe reports it. Only the brand and last four digits of the card are kept, for display.
 * See App\Services\Tenancy\Subscriptions.
 */
class CompanySubscription extends Model
{
    use CentralConnection;

    /** Stripe statuses in which the company counts as paying (a failed payment gets a grace period: past_due). */
    public const GOOD = ['trialing', 'active', 'past_due'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['current_period_end' => 'datetime', 'trial_end' => 'datetime', 'canceled_at' => 'datetime', 'cancel_at_period_end' => 'boolean', 'resubscribe_enabled' => 'boolean'];
    }

    public function isGood(): bool
    {
        return in_array($this->status, self::GOOD, true);
    }
}
