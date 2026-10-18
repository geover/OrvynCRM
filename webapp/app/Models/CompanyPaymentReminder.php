<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** A reminder about money sent to a company's administrators: by a platform administrator (manual) or by the daily follow-up. */
class CompanyPaymentReminder extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
