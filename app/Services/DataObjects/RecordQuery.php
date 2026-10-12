<?php

namespace App\Services\DataObjects;

use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Support\FullText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Translates a filter tree (the same shape saved queries and automation conditions use) into SQL.
 *
 *   {"match":"all","rules":[
 *      {"field":"status","op":"=","value":"Open"},
 *      {"field":"amount","op":">","value":1000},
 *      {"match":"any","rules":[ ... ]}
 *   ]}
 *
 * Indexed fields are filtered through data_object_field_index (an index seek, no JSON scan).
 * Non-indexed fields fall back to JSON extraction so nothing is ever unqueryable.
 */
class RecordQuery
{
    private const OPS = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'starts_with', 'in', 'empty', 'not_empty'];

    public function __construct(private readonly DataObject $object) {}

    public function build(array $filter = [], ?string $search = null): Builder
    {
        $query = DataObjectRecord::query()->where('data_object_records.data_object_id', $this->object->id);

        if ($search !== null && trim($search) !== '') {
            $this->applySearch($query, trim($search));
        }
        if (! empty($filter['rules'])) {
            $this->applyGroup($query, $filter);
        }

        return $query;
    }

    private function applySearch(Builder $query, string $term): void
    {
        if ($boolean = FullText::booleanQuery($term)) {
            $query->whereRaw('MATCH(search_text) AGAINST (? IN BOOLEAN MODE)', [$boolean]);   // prefix matching: "Bulkdea" finds "Bulkdeal"
        } else {
            $query->where('search_text', 'like', '%'.addcslashes($term, '%_\\').'%');
        }
    }

    private function applyGroup(Builder $query, array $group, string $boolean = 'and'): void
    {
        $query->where(function (Builder $q) use ($group) {
            $any = ($group['match'] ?? 'all') === 'any';
            foreach ($group['rules'] as $rule) {
                $method = $any ? 'orWhere' : 'where';
                if (isset($rule['rules'])) {
                    $q->{$method}(fn (Builder $nested) => $this->applyGroup($nested, $rule));
                } else {
                    $q->{$method}(fn (Builder $leaf) => $this->applyRule($leaf, $rule));
                }
            }
        }, boolean: $boolean);
    }

    private function applyRule(Builder $query, array $rule): void
    {
        $op = $rule['op'] ?? '=';
        if (! in_array($op, self::OPS, true)) {
            throw new InvalidArgumentException("Unsupported operator [$op]");
        }

        $field = $this->object->fields->firstWhere('key', $rule['field'] ?? null)
            ?? throw new InvalidArgumentException('Unknown field ['.($rule['field'] ?? '').']');
        $value = $rule['value'] ?? null;

        if ($field->is_indexed && in_array($op, ['empty', 'not_empty'], true)) {
            // the indexer skips empty values, so "has no index row" means "empty"
            $has = fn ($sub) => $sub->select('fi.record_id')->from('data_object_field_index as fi')->where('fi.field_id', $field->id);
            $op === 'empty' ? $query->whereNotIn('data_object_records.id', $has) : $query->whereIn('data_object_records.id', $has);

            return;
        }

        if ($field->is_indexed && ! in_array($op, ['empty', 'not_empty'], true)) {
            // Correlated EXISTS: with the usual LIMIT it stops at the first page of matches. (An IN subquery was measured at
            // 300k records: 14x slower for combined filters, because both id sets are materialised and intersected first.)
            $query->whereExists(function ($sub) use ($field, $op, $value) {
                $sub->select(DB::raw(1))->from('data_object_field_index as fi')
                    ->whereColumn('fi.record_id', 'data_object_records.id')
                    ->where('fi.field_id', $field->id);
                $this->compare($sub, $this->indexColumn($field->type, $value), $op, $value);
            });

            return;
        }

        // Laravel's JSON path syntax compiles to the right function on MySQL, MariaDB, Postgres and SQLite.
        $col = 'data_object_records.data->'.preg_replace('/[^A-Za-z0-9_]/', '', $field->key);

        match ($op) {
            'empty' => $query->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')),
            'not_empty' => $query->whereNotNull($col)->where($col, '!=', ''),
            default => $this->compare($query, $col, $op, $value),
        };
    }

    private function compare($query, $column, string $op, mixed $value): void
    {
        match ($op) {
            'contains' => $query->where($column, 'like', '%'.addcslashes((string) $value, '%_\\').'%'),
            'starts_with' => $query->where($column, 'like', addcslashes((string) $value, '%_\\').'%'),
            'in' => $query->whereIn($column, (array) $value),
            default => $query->where($column, $op, $value),
        };
    }

    private function indexColumn(string $type, mixed $value): string
    {
        return match (true) {
            $type === 'number' && is_numeric($value) => 'fi.value_number',
            in_array($type, ['date', 'datetime'], true) => 'fi.value_date',
            default => 'fi.value_string',
        };
    }
}
