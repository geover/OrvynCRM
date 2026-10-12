<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/** Folds $duplicate into $survivor: empty fields are filled, every related row is re-pointed, duplicate is soft-deleted. */
class MergeContacts
{
    private const RELATED = [
        'notes' => 'contact_id', 'tasks' => 'contact_id', 'activities' => 'contact_id',
        'email_messages' => 'contact_id', 'sms_messages' => 'contact_id',
    ];

    /** @param array{notes_tasks?: 'transfer'|'none', data_objects?: 'transfer'|'none'} $options */
    public function handle(Contact $survivor, Contact $duplicate, array $options = []): Contact
    {
        $moveHistory = ($options['notes_tasks'] ?? 'transfer') === 'transfer';
        $moveObjects = ($options['data_objects'] ?? 'transfer') === 'transfer';

        return DB::transaction(function () use ($survivor, $duplicate, $moveHistory, $moveObjects) {
            foreach ($duplicate->getAttributes() as $key => $value) {
                if (in_array($key, ['id', 'legacy_id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }
                if (blank($survivor->{$key}) && filled($value)) {
                    $survivor->{$key} = $duplicate->{$key};   // cast value, not raw JSON (avoids double-encoding)
                }
            }
            $survivor->save();

            foreach (self::RELATED as $table => $column) {
                // messages and the audit trail always follow the surviving contact; notes/tasks are optional
                if (! $moveHistory && in_array($table, ['notes', 'tasks'], true)) {
                    continue;
                }
                DB::table($table)->where($column, $duplicate->id)->update([$column => $survivor->id]);
            }

            // pivots with unique keys: ignore collisions
            DB::table('contact_tag')->where('contact_id', $duplicate->id)
                ->whereNotIn('tag_id', DB::table('contact_tag')->where('contact_id', $survivor->id)->select('tag_id'))
                ->update(['contact_id' => $survivor->id]);
            $moveObjects && DB::table('data_object_record_contact')->where('contact_id', $duplicate->id)
                ->whereNotExists(fn ($q) => $q->from('data_object_record_contact as x')
                    ->whereColumn('x.record_id', 'data_object_record_contact.record_id')
                    ->whereColumn('x.relationship_id', 'data_object_record_contact.relationship_id')
                    ->where('x.contact_id', $survivor->id))
                ->update(['contact_id' => $survivor->id]);

            $duplicate->delete();

            return $survivor->refresh();
        });
    }
}
