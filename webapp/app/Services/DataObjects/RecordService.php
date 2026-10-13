<?php

namespace App\Services\DataObjects;

use App\Events\DataObjectRecordChanged;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordService
{
    /** Validate raw input against the data object's field definitions and return the clean document. */
    public function validate(DataObject $object, array $input, bool $partial = false): array
    {
        $rules = [];
        foreach ($object->fields as $field) {
            $r = [$partial || ! $field->is_required ? 'nullable' : 'required'];
            $r = array_merge($r, match ($field->type) {
                'number' => ['numeric'],
                'date', 'datetime' => ['date'],
                'email' => ['email'],
                'url' => ['url'],
                'checkbox' => ['boolean'],
                'multiselect' => ['array'],
                'select' => $field->options['choices'] ?? null ? [Rule::in($field->options['choices'])] : ['string'],
                default => ['string'],
            });
            $rules[$field->key] = $partial ? array_merge(['sometimes'], $r) : $r;
        }

        $validated = array_intersect_key(Validator::make($input, $rules)->validate(), $rules);

        // Store real JSON types: "777" from a CSV / automation / form becomes 777, "1" becomes true.
        foreach ($object->fields as $field) {
            $v = $validated[$field->key] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $validated[$field->key] = match ($field->type) {
                'number' => $v + 0,
                'checkbox' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                default => $v,
            };
        }

        return $validated;
    }

    public function create(DataObject $object, array $input, ?int $userId = null, array $contacts = []): DataObjectRecord
    {
        $data = $this->validate($object->loadMissing('fields'), $input);

        return DB::transaction(function () use ($object, $data, $userId, $contacts) {
            $record = $object->records()->create(['data' => $data, 'created_by' => $userId]);
            foreach ($contacts as $link) {
                $this->attach($record, $link['contact_id'], $link['relationship_id'], $link['values'] ?? null);
            }
            $this->log($record, 'record.created', ['object' => $object->name]);
            DataObjectRecordChanged::dispatch($record, 'created');

            return $record;
        });
    }

    public function update(DataObjectRecord $record, array $input): DataObjectRecord
    {
        $object = $record->dataObject->loadMissing('fields');
        $changes = $this->validate($object, $input, partial: true);

        $before = $record->data ?? [];
        $record->update(['data' => array_merge($before, $changes)]);

        // Field-level diff for the item history ("what changed, from what, to what")
        $diff = [];
        foreach ($changes as $key => $new) {
            $old = $before[$key] ?? null;
            if ($old != $new) {
                $diff[$key] = [$old, $new];
            }
        }
        if ($diff) {
            $this->log($record, 'record.updated', ['object' => $object->name, 'diff' => $diff]);
        }
        DataObjectRecordChanged::dispatch($record, 'updated');

        return $record;
    }

    public function attach(DataObjectRecord $record, int $contactId, int $relationshipId, ?array $values = null): void
    {
        $rule = $record->dataObject->relationships()->where('relationship_id', $relationshipId)->first()?->pivot;

        if ($rule && $rule->is_exclusive) {
            $taken = DB::table('data_object_record_contact')
                ->where('record_id', $record->id)->where('relationship_id', $relationshipId)
                ->where('contact_id', '!=', $contactId)->exists();
            if ($taken) {
                throw ValidationException::withMessages(['relationship_id' => 'This relationship is exclusive and already assigned.']);
            }
        }
        if ($rule?->max_contacts) {
            $count = DB::table('data_object_record_contact')
                ->where('record_id', $record->id)->where('relationship_id', $relationshipId)->count();
            if ($count >= $rule->max_contacts) {
                throw ValidationException::withMessages(['relationship_id' => 'Maximum number of contacts reached for this relationship.']);
            }
        }

        $record->contacts()->syncWithoutDetaching([
            $contactId => ['relationship_id' => $relationshipId, 'contact_values' => $values ? json_encode($values) : null],
        ]);
        Contact::whereKey($contactId)->touch();
        $this->log($record, 'record.linked', ['object' => $record->dataObject->name, 'contact_id' => $contactId, 'relationship_id' => $relationshipId]);

        // Tell the user whose own contact was connected (unless they did it themselves).
        $userId = DB::table('users')->where('contact_id', $contactId)->value('id');
        if ($userId && $userId !== Auth::id()) {
            DB::table('notifications_feed')->insert([
                'user_id' => $userId, 'title' => 'You were connected to a '.$record->dataObject->name, 'body' => null,
                'url' => "/objects/{$record->data_object_id}", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** One activity per linked contact (capped) so the item history AND each contact's timeline show the event. */
    private function log(DataObjectRecord $record, string $type, array $data): void
    {
        $contactIds = DB::table('data_object_record_contact')->where('record_id', $record->id)->limit(10)->pluck('contact_id');
        $rows = ($contactIds->isEmpty() ? collect([null]) : $contactIds)->map(fn ($cid) => [
            'contact_id' => $cid, 'subject_type' => DataObjectRecord::class, 'subject_id' => $record->id,
            'causer_id' => Auth::id(), 'type' => $type, 'data' => json_encode($data), 'created_at' => now(),
        ])->all();
        Activity::insert($rows);
    }
}
