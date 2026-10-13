<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** A company's use of its tier in one month. See App\Services\Tenancy\UsageTracker. */
class CompanyUsage extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /** @return 'ok'|'near'|'at' the worst of the three resources: near from 80 %, at from 100 % */
    public function level(): string
    {
        $worst = 0;
        foreach ([[$this->users, $this->users_limit], [$this->sms, $this->sms_limit], [$this->numbers, $this->numbers_limit]] as [$used, $limit]) {
            $limit && $worst = max($worst, (int) floor($used * 100 / $limit));
        }

        return $worst >= 100 ? 'at' : ($worst >= 80 ? 'near' : 'ok');
    }
}
