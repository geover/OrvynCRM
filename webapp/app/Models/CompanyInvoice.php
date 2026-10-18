<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** One Stripe invoice of a company and how paying it went. Amounts are in the smallest unit of the currency. */
class CompanyInvoice extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime', 'issued_at' => 'datetime', 'paid_at' => 'datetime', 'failed_at' => 'datetime'];
    }
}
