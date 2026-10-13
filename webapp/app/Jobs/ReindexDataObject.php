<?php

namespace App\Jobs;

use App\Models\DataObjectRecord;
use App\Services\DataObjects\RecordIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Rebuilds search_text + typed index rows for every record of an object (after field/index settings change). */
class ReindexDataObject implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public int $dataObjectId)
    {
        $this->onConnection(config('queue.long'))->onQueue('default');   // long job: its own visibility timeout, see config/queue.php
    }

    public function handle(RecordIndexer $indexer): void
    {
        $indexer->forget($this->dataObjectId);

        DataObjectRecord::where('data_object_id', $this->dataObjectId)
            ->chunkById(500, fn ($records) => $records->each(fn ($r) => $indexer->index($r)));
    }
}
