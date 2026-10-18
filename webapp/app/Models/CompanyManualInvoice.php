<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** An invoice the platform issued to a company it bills itself. Paid when `paid_on` is set; overdue when unpaid past `due_on`. */
class CompanyManualInvoice extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date', 'paid_on' => 'date'];
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereNull('paid_on');
    }

    public function isOverdue(): bool
    {
        return $this->paid_on === null && $this->due_on->lt(now()->startOfDay());
    }

    /** paid | overdue | sent */
    public function state(): string
    {
        return $this->paid_on !== null ? 'paid' : ($this->isOverdue() ? 'overdue' : 'sent');
    }
}
