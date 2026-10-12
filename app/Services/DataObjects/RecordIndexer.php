<?php

namespace App\Services\DataObjects;

use App\Models\DataObjectField;
use App\Models\DataObjectRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the read-optimised side of a data object record:
 *  - search_text      -> FULLTEXT-searchable flattened values
 *  - field_index rows -> typed, indexed copies of fields flagged is_indexed
 *
 * The JSON `data` column remains the single source of truth.
 */
class RecordIndexer
{
    /** @var array<int, Collection> per-request field cache */
    private array $fieldCache = [];

    public function index(DataObjectRecord $record): void
    {
        $fields = $this->fields($record->data_object_id);
        $data = $record->data ?? [];

        $searchText = $fields->filter(fn ($f) => in_array($f->type, ['text', 'textarea', 'email', 'select', 'url', 'number'], true))
            ->map(fn ($f) => $this->scalar($data[$f->key] ?? null))
            ->filter()->implode(' ');

        // updateQuietly avoids re-triggering the saved hook.
        if ($record->search_text !== $searchText) {
            DB::table('data_object_records')->where('id', $record->id)->update(['search_text' => $searchText]);
            $record->search_text = $searchText;
        }

        $rows = [];
        foreach ($fields->where('is_indexed', true) as $field) {
            if (! array_key_exists($field->key, $data) || $data[$field->key] === null || $data[$field->key] === '') {
                continue;
            }
            foreach ((array) $data[$field->key] as $value) {
                $rows[] = $this->row($record->id, $field, $value);
            }
        }

        DB::transaction(function () use ($record, $fields, $rows) {
            DB::table('data_object_field_index')
                ->where('record_id', $record->id)->whereNull('contact_id')
                ->whereIn('field_id', $fields->where('is_indexed', true)->pluck('id'))
                ->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('data_object_field_index')->insert($chunk);
            }
        });
    }

    public function forget(int $objectId): void
    {
        unset($this->fieldCache[$objectId]);
    }

    private function fields(int $objectId)
    {
        return $this->fieldCache[$objectId] ??= DataObjectField::where('data_object_id', $objectId)->get();
    }

    private function row(int $recordId, DataObjectField $field, mixed $value): array
    {
        $isNumber = $field->type === 'number' && is_numeric($value);
        $isDate = in_array($field->type, ['date', 'datetime'], true) && strtotime((string) $value) !== false;

        return [
            'record_id' => $recordId,
            'field_id' => $field->id,
            'contact_id' => null,
            'value_string' => $isNumber || $isDate ? null : mb_substr($this->scalar($value), 0, 191),
            'value_number' => $isNumber ? $value : null,
            'value_date' => $isDate ? date('Y-m-d H:i:s', strtotime((string) $value)) : null,
        ];
    }

    private function scalar(mixed $v): string
    {
        return is_array($v) ? implode(' ', array_map(fn ($x) => $this->scalar($x), $v)) : (string) ($v ?? '');
    }
}
