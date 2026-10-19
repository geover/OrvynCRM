<?php

namespace App\Services\Queries;

use App\Models\Contact;
use App\Models\DataObject;
use App\Services\DataObjects\RecordQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Runs a saved-query definition:
 *   scope=contact:      {contact_filter}
 *   scope=data_object:  {data_object_id, filter, contact_filter?, relationship_ids?, columns?}
 *
 * A person looking at the result can narrow it further without changing the query: `_search` (words that must appear), `_ids` (only these
 * rows). They sit in the definition so the CSV and the e-mail/text batches act on exactly what is on screen. `$view` adds the order
 * (`sort` is a column key, `dir` asc or desc) for the rows shown.
 */
class QueryRunner
{
    public function __construct(private readonly ContactQuery $contacts) {}

    /**
     * @param  array{sort?: ?string, dir?: ?string}  $view
     * @return array{columns: array, rows: Collection, total: int}
     */
    public function run(string $scope, array $definition, int $limit = 25, int $offset = 0, array $view = []): array
    {
        return $scope === 'contact' ? $this->runContacts($definition, $limit, $offset, $view) : $this->runRecords($definition, $limit, $offset, $view);
    }

    /** Builder over contacts that match the query (used by batch email/sms and exports). */
    public function contactBuilder(string $scope, array $definition): Builder
    {
        if ($scope === 'contact') {
            return $this->narrowContacts($this->contacts->build($definition['contact_filter'] ?? []), $definition);
        }
        $records = $this->recordBuilder($definition)->select('data_object_records.id');

        return Contact::query()->whereIn('contacts.id', function ($q) use ($records, $definition) {
            $q->select('rc.contact_id')->from('data_object_record_contact as rc')
                ->whereIn('rc.record_id', $records->toBase())
                ->when(! empty($definition['relationship_ids']), fn ($w) => $w->whereIn('rc.relationship_id', $definition['relationship_ids']));
        });
    }

    private function runContacts(array $def, int $limit, int $offset, array $view): array
    {
        $q = $this->narrowContacts($this->contacts->build($def['contact_filter'] ?? []), $def);
        $total = (clone $q)->count();
        $cols = collect(ContactQuery::FIELDS)->filter(fn ($f) => in_array($f['key'], $def['columns'] ?? ['first_name', 'last_name', 'email', 'phone', 'company_name'], true))->values();

        $sort = $cols->pluck('key')->contains($view['sort'] ?? null) && ! in_array($view['sort'], ['tag'], true) ? $view['sort'] : null;
        $dir = ($view['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $sort ? $q->orderBy("contacts.$sort", $dir)->orderBy('contacts.id') : $q->orderByDesc('contacts.id');

        $rows = $q->offset($offset)->limit($limit)->get()->map(fn ($c) => [
            'id' => $c->id, 'contact_id' => $c->id, 'label' => $c->full_name,
            'values' => $cols->mapWithKeys(fn ($f) => [$f['key'] => $c->{$f['key']}])->all(),
        ]);

        return ['columns' => $cols->map(fn ($f) => ['key' => $f['key'], 'label' => $f['label']])->all(), 'rows' => $rows, 'total' => $total];
    }

    private function runRecords(array $def, int $limit, int $offset, array $view): array
    {
        $object = DataObject::with('fields')->findOrFail($def['data_object_id']);
        $q = $this->recordBuilder($def);
        $total = (clone $q)->count();

        $keys = $def['columns'] ?? [];
        $cols = $object->fields->filter(fn ($f) => $keys ? in_array($f->key, $keys, true) : $f->show_in_list)->values();
        $cols = $cols->isEmpty() ? $object->fields->take(5) : $cols;

        $sort = $cols->pluck('key')->contains($view['sort'] ?? null) ? $view['sort'] : null;
        $dir = ($view['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $sort ? $q->orderBy("data_object_records.data->$sort", $dir)->orderBy('data_object_records.id') : $q->orderByDesc('data_object_records.id');

        $rows = $q->with('contacts:id,first_name,last_name')->offset($offset)->limit($limit)->get()->map(fn ($r) => [
            'id' => $r->id, 'data_object_id' => $object->id,
            'values' => $cols->mapWithKeys(fn ($f) => [$f->key => $r->data[$f->key] ?? null])->all(),
            'contacts' => $r->contacts->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name, 'relationship_id' => $c->pivot->relationship_id]),
        ]);

        return ['columns' => $cols->map(fn ($f) => ['key' => $f->key, 'label' => $f->label])->values()->all(), 'rows' => $rows, 'total' => $total];
    }

    /** The words a person typed above the result, and the rows they ticked. */
    private function narrowContacts(Builder $q, array $def): Builder
    {
        if (! empty($def['_ids'])) {
            $q->whereIn('contacts.id', $def['_ids']);
        }
        foreach (preg_split('/\s+/', trim((string) ($def['_search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $q->where(fn ($w) => $w->where('contacts.first_name', 'like', $like)->orWhere('contacts.last_name', 'like', $like)->orWhere('contacts.email', 'like', $like)
                ->orWhere('contacts.phone', 'like', $like)->orWhere('contacts.company_name', 'like', $like));
        }

        return $q;
    }

    private function recordBuilder(array $def): Builder
    {
        $object = DataObject::with('fields')->findOrFail($def['data_object_id']);
        $q = (new RecordQuery($object))->build($def['filter'] ?? [], $def['_search'] ?? null);
        if (! empty($def['_ids'])) {
            $q->whereIn('data_object_records.id', $def['_ids']);
        }

        $needsContacts = ! empty($def['contact_filter']['rules']) || ! empty($def['relationship_ids']);
        if ($needsContacts) {
            $q->whereExists(function ($sub) use ($def) {
                $sub->selectRaw('1')->from('data_object_record_contact as rc')->whereColumn('rc.record_id', 'data_object_records.id')
                    ->when(! empty($def['relationship_ids']), fn ($w) => $w->whereIn('rc.relationship_id', $def['relationship_ids']))
                    ->when(! empty($def['contact_filter']['rules']), fn ($w) => $w->whereIn('rc.contact_id',
                        $this->contacts->build($def['contact_filter'])->select('contacts.id')->toBase()));
            });
        }

        return $q;
    }
}
