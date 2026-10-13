<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The billing details a company gave the platform (who to invoice, where, tax number). No card or bank data is kept:
 * charging is not part of the application yet, so this is what the platform needs to invoice. See App\Services\Tenancy\Plans.
 */
class CompanyBilling extends Model
{
    use CentralConnection;

    /** The details that must be filled in before they count as complete. */
    public const REQUIRED = ['legal_name', 'contact_name', 'contact_email', 'address_line1', 'city', 'postal_code', 'country'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function isComplete(): bool
    {
        foreach (self::REQUIRED as $field) {
            if (trim((string) $this->{$field}) === '') {
                return false;
            }
        }

        return true;
    }
}
