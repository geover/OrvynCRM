<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\DataTransfer;
use App\Models\Tag;
use App\Services\Data\DataTransferNotices;
use App\Services\DataObjects\RecordService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Streams a CSV (never loads it whole) and creates/updates contacts or data-object records.
 * Contacts match on e-mail; records match on the chosen identifying field. Row errors are counted
 * and the first 25 are kept on the transfer for the admin to read.
 */
class ProcessDataImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    private array $errors = [];

    public function __construct(public int $transferId)
    {
        $this->onConnection(config('queue.long'))->onQueue('imports');   // long job: its own visibility timeout, see config/queue.php
    }

    public function handle(RecordService $records): void
    {
        $t = DataTransfer::findOrFail($this->transferId);
        $t->update(['status' => 'running']);
        DataTransferNotices::started($t);
        $path = Storage::disk('local')->path($t->file_path);
        $map = array_filter($t->options['mapping'] ?? []);

        $fh = fopen($path, 'r');
        $headers = array_map(fn ($h) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($fh) ?: []);
        $object = $t->resource === 'data_objects' ? DataObject::with('fields')->find($t->options['data_object_id']) : null;

        $line = 1;
        $processed = 0;
        $failed = 0;
        try {
            while (($row = fgetcsv($fh)) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;   // blank line
                }
                $data = [];
                foreach ($headers as $i => $h) {
                    if (isset($map[$h]) && isset($row[$i]) && trim($row[$i]) !== '') {
                        $data[$map[$h]] = trim($row[$i]);
                    }
                }
                try {
                    $object ? $this->importRecord($t, $object, $data, $records) : $this->importContact($data);
                    $processed++;
                } catch (ValidationException $e) {
                    $failed++;
                    $this->note("Row $line: ".collect($e->errors())->flatten()->first());
                } catch (\Throwable $e) {
                    $failed++;
                    $this->note("Row $line: ".$e->getMessage());
                }
                if (($processed + $failed) % 200 === 0) {
                    $t->update(['processed' => $processed, 'failed' => $failed]);   // progress for the UI
                }
            }
        } finally {
            fclose($fh);
        }

        $t->update(['status' => 'done', 'processed' => $processed, 'failed' => $failed, 'total' => $processed + $failed, 'error' => $this->errors ? implode("\n", $this->errors) : null]);
        Storage::disk('local')->delete($t->file_path);
        DataTransferNotices::finished($t->fresh());
    }

    /** The queue gave up on it (a crash, not a bad row): say so on the transfer and to the person who started it. */
    public function failed(\Throwable $e): void
    {
        if ($t = DataTransfer::find($this->transferId)) {
            $t->update(['status' => 'failed', 'error' => $e->getMessage()]);
            DataTransferNotices::finished($t->fresh());
        }
    }

    private function importContact(array $d): void
    {
        $v = Validator::make($d, ['email' => ['required', 'email'], 'birthday' => ['nullable', 'date'], 'website' => ['nullable', 'string', 'max:255']])->validate();
        $tags = isset($d['tags']) ? array_filter(array_map('trim', explode(',', $d['tags']))) : [];
        unset($d['tags']);
        $d['email'] = strtolower($d['email']);

        DB::transaction(function () use ($d, $tags) {
            $contact = Contact::firstOrNew(['email' => $d['email']]);
            // never blank out existing data with an empty CSV cell: only overwrite with provided values
            $contact->fill($d + ($contact->exists ? [] : ['source' => 'import']))->save();
            if ($tags) {
                $contact->tags()->syncWithoutDetaching(collect($tags)->map(fn ($n) => Tag::firstOrCreate(['name' => $n])->id)->all());
            }
        });
    }

    private function importRecord(DataTransfer $t, DataObject $object, array $d, RecordService $records): void
    {
        $email = $d['_contact_email'] ?? null;
        unset($d['_contact_email']);
        $idField = $t->options['identifying_field'] ?? null;

        $record = null;
        if ($idField && isset($d[$idField])) {
            $field = $object->fields->firstWhere('key', $idField);
            $q = DataObjectRecord::where('data_object_id', $object->id);
            $field->is_indexed
                ? $q->whereIn('id', DB::table('data_object_field_index')->where('field_id', $field->id)->where('value_string', mb_substr($d[$idField], 0, 191))->select('record_id'))
                : $q->where('data->'.$idField, $d[$idField]);
            $record = $q->first();
        }

        // a "Any of" cell (several choices in one CSV cell, separated by ; or ,) becomes the list the field holds
        foreach ($object->fields->where('type', 'multiselect') as $field) {
            if (isset($d[$field->key]) && is_string($d[$field->key])) {
                $d[$field->key] = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $d[$field->key]))));
            }
        }

        $links = [];
        if ($email && ($rel = $t->options['relationship_id'] ?? null)) {
            $contact = Contact::where('email', strtolower($email))->first();
            $contact ? $links[] = ['contact_id' => $contact->id, 'relationship_id' => $rel] : throw ValidationException::withMessages(['contact' => "No contact with e-mail $email"]);
        }

        if ($record) {
            $records->update($record, $d);
            foreach ($links as $l) {
                $records->attach($record, $l['contact_id'], $l['relationship_id']);
            }
        } else {
            $records->create($object, $d, $t->user_id, $links);
        }
    }

    private function note(string $m): void
    {
        count($this->errors) < 25 && $this->errors[] = $m;
    }
}
