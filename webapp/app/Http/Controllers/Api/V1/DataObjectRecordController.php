<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\WorkflowBoard;
use App\Services\DataObjects\RecordQuery;
use App\Services\DataObjects\RecordService;
use App\Support\IgnoredInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Replaces V1::data_object_get/post/delete and connected_data_* endpoints. */
class DataObjectRecordController extends Controller
{
    public function index(Request $request, DataObject $dataObject)
    {
        $this->authorizeObject($request, $dataObject, 'view');
        $dataObject->load('fields');

        try {
            $query = (new RecordQuery($dataObject))->build((array) $request->input('filter', []), $request->string('q')->toString() ?: null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($contactId = $request->integer('contact_id')) {
            $query->whereIn('data_object_records.id', fn ($q) => $q->select('record_id')->from('data_object_record_contact')->where('contact_id', $contactId));
        }

        $this->applySort($query, $dataObject, $request->string('sort')->toString());

        $query->with('contacts:id,first_name,last_name')->orderByDesc('data_object_records.id');
        $perPage = min($request->integer('per_page', 25), 100);

        // `?page=N` gives numbered pages with totals (the items table); without it, cursor pagination (API clients, the contact's data tab)
        return RecordResource::collection($request->has('page') ? $query->paginate($perPage)->withQueryString() : $query->cursorPaginate($perPage));
    }

    /**
     * The numbers above an object's items: how many, what is new, how many contacts they are linked to, when something last changed, how the items
     * spread over the object's first dropdown field, and the boards that show them.
     */
    public function overview(Request $request, DataObject $dataObject): JsonResponse
    {
        $this->authorizeObject($request, $dataObject, 'view');
        $dataObject->load('fields');
        $records = fn () => DB::table('data_object_records')->where('data_object_id', $dataObject->id)->whereNull('deleted_at');

        $contacts = DB::table('data_object_record_contact as rc')->join('data_object_records as r', 'r.id', '=', 'rc.record_id')
            ->where('r.data_object_id', $dataObject->id)->whereNull('r.deleted_at')->distinct()->count('rc.contact_id');

        // how the items spread over one dropdown field: the one asked for (?field=key), else the one shown in the list, else the first
        $selects = $dataObject->fields->where('type', 'select')->filter(fn ($f) => ! empty($f->options['choices']))->values();
        $field = $selects->firstWhere('key', $request->string('field')->toString()) ?? $selects->sortByDesc('show_in_list')->first();
        $spread = null;
        if ($field && ! empty($field->options['choices'])) {
            $choices = array_slice($field->options['choices'], 0, 12);
            $counts = $field->is_indexed
                ? DB::table('data_object_field_index')->where('field_id', $field->id)->groupBy('value_string')->selectRaw('value_string as v, count(*) as n')->pluck('n', 'v')
                : collect($choices)->mapWithKeys(fn ($c) => [$c => (new RecordQuery($dataObject))->build(['rules' => [['field' => $field->key, 'op' => '=', 'value' => $c]]])->count()]);
            $values = collect($choices)->map(fn ($c) => ['value' => $c, 'count' => (int) ($counts[$c] ?? 0)])->values();
            $spread = ['key' => $field->key, 'label' => $field->label, 'values' => $values->all(), 'empty' => max(0, $records()->count() - (int) collect($field->options['choices'])->sum(fn ($c) => (int) ($counts[$c] ?? 0)))];
        }

        $last = $records()->max('updated_at');

        return response()->json(['data' => [
            'total' => $records()->count(),
            'added_7d' => $records()->where('created_at', '>=', now()->subDays(7))->count(),
            'added_30d' => $records()->where('created_at', '>=', now()->subDays(30))->count(),
            'contacts' => $contacts,
            'last_change' => $last ? Carbon::parse($last)->toIso8601String() : null,
            'spread' => $spread,
            'selects' => $selects->map(fn ($f) => ['key' => $f->key, 'label' => $f->label])->all(),         // the dropdowns the bar can break the items down by
            'boards' => WorkflowBoard::where('data_object_id', $dataObject->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    /**
     * Sorts by one of the object's fields (`?sort=amount`, `?sort=-amount` for descending). Indexed fields sort through their typed index
     * rows; any other field sorts by its stored value. An unknown field is ignored, so the newest records come first.
     */
    private function applySort($query, DataObject $dataObject, string $sort): void
    {
        $field = $sort === '' ? null : $dataObject->fields->firstWhere('key', ltrim($sort, '-'));
        if (! $field) {
            return;
        }
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->select('data_object_records.*');
        if ($field->is_indexed) {
            $column = match (true) {
                $field->type === 'number' => 'value_number',
                in_array($field->type, ['date', 'datetime'], true) => 'value_date',
                default => 'value_string',
            };
            $query->leftJoin('data_object_field_index as sort_index', fn ($join) => $join->on('sort_index.record_id', '=', 'data_object_records.id')->where('sort_index.field_id', $field->id))
                ->addSelect("sort_index.{$column} as sort_value");
        } else {
            $path = $query->getQuery()->getGrammar()->wrap('data_object_records.data->'.preg_replace('/[^A-Za-z0-9_]/', '', $field->key));
            $query->selectRaw("{$path} as sort_value");
        }
        $query->orderBy('sort_value', $direction);
    }

    public function show(Request $request, DataObject $dataObject, DataObjectRecord $record): RecordResource
    {
        $this->authorizeObject($request, $dataObject, 'view');
        abort_unless($record->data_object_id === $dataObject->id, 404);

        return new RecordResource($record->load('contacts:id,first_name,last_name,email,phone'));      // the e-mail and phone let the item's page offer to write to each contact
    }

    public function store(Request $request, DataObject $dataObject, RecordService $service): JsonResponse
    {
        $this->authorizeObject($request, $dataObject, 'create');
        $links = $request->validate([
            'contacts' => ['sometimes', 'array'],
            'contacts.*.contact_id' => ['required', 'exists:contacts,id'],
            'contacts.*.relationship_id' => ['required', 'exists:data_object_relationships,id'],
        ])['contacts'] ?? [];

        IgnoredInput::covers('data');                                      // the item's own field rules check these
        $record = $service->create($dataObject, (array) $request->input('data', []), $request->user()->id, $links);

        return (new RecordResource($record->load('contacts:id,first_name,last_name')))->response()->setStatusCode(201);
    }

    public function update(Request $request, DataObject $dataObject, DataObjectRecord $record, RecordService $service): RecordResource
    {
        $this->authorizeObject($request, $dataObject, 'edit');
        abort_unless($record->data_object_id === $dataObject->id, 404);

        IgnoredInput::covers('data');

        return new RecordResource($service->update($record, (array) $request->input('data', [])));
    }

    public function destroy(Request $request, DataObject $dataObject, DataObjectRecord $record): JsonResponse
    {
        $this->authorizeObject($request, $dataObject, 'delete');
        abort_unless($record->data_object_id === $dataObject->id, 404);
        $record->delete();
        DB::table('data_object_field_index')->where('record_id', $record->id)->delete();   // keep the index free of dead rows

        return response()->json(null, 204);
    }

    public function attach(Request $request, DataObject $dataObject, DataObjectRecord $record, RecordService $service): JsonResponse
    {
        $this->authorizeObject($request, $dataObject, 'edit');
        $d = $request->validate([
            'contact_id' => ['required', 'exists:contacts,id'], 'relationship_id' => ['required', 'exists:data_object_relationships,id'],
            'values' => ['nullable', 'array'],
        ]);
        $service->attach($record, $d['contact_id'], $d['relationship_id'], $d['values'] ?? null);

        return response()->json(['data' => new RecordResource($record->load('contacts:id,first_name,last_name'))]);
    }

    public function detach(Request $request, DataObject $dataObject, DataObjectRecord $record, int $contactId): JsonResponse
    {
        $this->authorizeObject($request, $dataObject, 'edit');
        $record->contacts()->detach($contactId);

        return response()->json(null, 204);
    }

    private function authorizeObject(Request $request, DataObject $object, string $ability): void
    {
        abort_unless($request->user()->hasPermission("data_objects.{$object->id}.$ability") || $request->user()->hasPermission("data_objects.$ability"), 403);
    }
}
