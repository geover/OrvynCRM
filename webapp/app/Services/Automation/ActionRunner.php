<?php

namespace App\Services\Automation;

use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\DataObjectRecord;
use App\Models\Tag;
use App\Models\Task;
use App\Services\DataObjects\RecordService;
use App\Services\Messaging\MessageComposer;
use App\Services\Webhooks\WebhookDispatcher;

/** Executes the ordered action list of an automation against the record (and its linked contacts). */
class ActionRunner
{
    public function __construct(
        private readonly MessageComposer $messages,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    public function run(AutomationRun $run): array
    {
        $record = DataObjectRecord::with('contacts')->find($run->data_object_record_id);
        if (! $record) {
            return [['status' => 'skipped', 'reason' => 'record_missing']];
        }

        $results = [];
        foreach ($run->automation->actions ?? [] as $i => $action) {
            $results[$i] = ['type' => $action['type'] ?? null, 'status' => 'ok'];
            try {
                $this->execute($action, $record);
            } catch (\Throwable $e) {
                $results[$i] = ['type' => $action['type'] ?? null, 'status' => 'failed', 'error' => $e->getMessage()];
                report($e);
            }
        }

        return $results;
    }

    private function execute(array $action, DataObjectRecord $record): void
    {
        $contacts = $this->targets($action, $record);

        match ($action['type'] ?? null) {
            'send_email' => $contacts->each(fn (Contact $c) => $this->messages->queueEmail($c, $action, $record)),
            'send_sms' => $contacts->each(fn (Contact $c) => $this->messages->queueSms($c, $action, $record)),
            'add_tag' => $contacts->each(fn (Contact $c) => $c->tags()->syncWithoutDetaching([
                Tag::firstOrCreate(['name' => $action['tag']])->id,
            ])),
            'remove_tag' => $contacts->each(fn (Contact $c) => $c->tags()->detach(Tag::where('name', $action['tag'])->value('id'))),
            'create_task' => $contacts->each(fn (Contact $c) => Task::create([
                'contact_id' => $c->id,
                'data_object_id' => $record->data_object_id,
                'assigned_to' => $action['assign_to'] ?? $c->owner_id,
                // task text is plain text, so merge without HTML escaping
                'title' => $this->messages->merge($action['title'] ?? 'Follow up', $this->messages->context($c, $record), escape: false),
                'description' => isset($action['description']) ? $this->messages->merge($action['description'], $this->messages->context($c, $record), escape: false) : null,
                'due_at' => now()->addMinutes((int) ($action['due_in_minutes'] ?? 1440)),
            ])),
            'update_field' => $this->updateField($record, $action),
            'webhook' => $this->webhooks->send($action['endpoint_id'], 'automation.triggered', ['record' => $record->only(['id', 'data'])]),
            default => throw new \InvalidArgumentException('Unknown automation action ['.($action['type'] ?? '').']'),
        };
    }

    /** Actions can target all linked contacts or only those with a given relationship. */
    private function targets(array $action, DataObjectRecord $record)
    {
        return $record->contacts->when(
            ! empty($action['relationship_id']),
            fn ($c) => $c->filter(fn ($contact) => (int) $contact->pivot->relationship_id === (int) $action['relationship_id']),
        );
    }

    /** Goes through RecordService so the new value is validated against the field type and automations/indexes stay consistent. */
    private function updateField(DataObjectRecord $record, array $action): void
    {
        app(RecordService::class)->update($record, [$action['field'] => $action['value'] ?? null]);
    }
}
