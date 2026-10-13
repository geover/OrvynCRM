<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** An approved (or platform-set) automation limit for ONE data object of a company, replacing the company-wide default. */
class ObjectAutomationLimit extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];
}
