<?php

namespace App\Jobs;

use App\Models\SavedQuery;
use App\Services\Messaging\MessageComposer;
use App\Services\Queries\QueryRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fan a batch email/SMS out to every contact matched by a saved query. Delivery itself is done by the normal message queue. */
class QueueBatchMessages implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public int $savedQueryId, public string $channel, public array $spec, public int $userId)
    {
        $this->onConnection(config('queue.long'))->onQueue('messages');   // long job: its own visibility timeout, see config/queue.php
    }

    public function handle(QueryRunner $runner, MessageComposer $composer): void
    {
        $query = SavedQuery::findOrFail($this->savedQueryId);
        $contacts = $runner->contactBuilder($query->scope, $query->definition)->distinct();

        // DispatchDueMessages (every minute) picks the rows up; staggering is done there with the rate limiter.
        $contacts->select('contacts.*')->chunkById(500, function ($chunk) use ($composer) {
            foreach ($chunk as $contact) {
                $m = $this->channel === 'email' ? $composer->queueEmail($contact, $this->spec) : $composer->queueSms($contact, $this->spec);
                $m?->update(['user_id' => $this->userId]);
            }
        }, 'contacts.id', 'id');
    }
}
