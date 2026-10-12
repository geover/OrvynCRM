<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Membership extends Model
{
    use CentralConnection;

    protected $table = 'memberships';

    protected $guarded = ['id'];
}
