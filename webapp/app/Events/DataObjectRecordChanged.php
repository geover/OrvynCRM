<?php

namespace App\Events;

use App\Models\DataObjectRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DataObjectRecordChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly DataObjectRecord $record, public readonly string $change) {}
}
