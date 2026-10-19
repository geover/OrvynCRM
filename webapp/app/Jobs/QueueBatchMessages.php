<?php

namespace App\Jobs;

use App\Models\Blast;
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

    /** @param  array{scope: string, definition: array<string, mixed>}|null  $adhoc  a search that was not saved (then there is no $savedQueryId) */
    public function __construct(public ?int $savedQueryId, public string $channel, public array $spec, public int $userId, public ?array $adhoc = null, public ?int $blastId = null)
    {
        $this->onConnection(config('queue.long'))->onQueue('messages');   // long job: its own visibility timeout, see config/queue.php
    }

    public function handle(QueryRunner $runner, MessageComposer $composer): void
    {
        $query = $this->adhoc ? null : SavedQuery::findOrFail($this->savedQueryId);
        $contacts = $runner->contactBuilder($this->adhoc['scope'] ?? $query->scope, $this->adhoc['definition'] ?? $query->definition)->distinct();

        // DispatchDueMessages (every minute) picks the rows up; staggering is done there with the rate limiter.
        $matched = 0;
        $made = 0;
        $contacts->select('contacts.*')->chunkById(500, function ($chunk) use ($composer, &$matched, &$made) {
            foreach ($chunk as $contact) {
                $matched++;
                $m = $this->channel === 'email' ? $composer->queueEmail($contact, $this->spec) : $composer->queueSms($contact, $this->spec);
                if ($m) {
                    $m->update(['user_id' => $this->userId, 'blast_id' => $this->blastId]);
                    $made++;
                }
            }
            $this->blastId && Blast::whereKey($this->blastId)->update(['matched' => $matched, 'queued' => $made]);     // the numbers grow while it runs
        }, 'contacts.id', 'id');

        $this->blastId && Blast::whereKey($this->blastId)->update(['matched' => $matched, 'queued' => $made, 'finished_queuing_at' => now()]);
    }
}
