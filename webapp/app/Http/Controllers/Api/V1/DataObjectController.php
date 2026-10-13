<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ReindexDataObject;
use App\Models\DataObject;
use App\Services\Tenancy\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Schema management for custom objects (Macanta "Connected Data" groups + field builder). */
class DataObjectController extends Controller
{
    public function index()
    {
        return response()->json(['data' => DataObject::withCount('records')->with('fields', 'relationships')->orderBy('name')->get()]);
    }

    public function show(DataObject $dataObject)
    {
        return response()->json(['data' => $dataObject->load('fields', 'relationships')]);
    }

    public function store(Request $request, Limits $limits): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $this->rules($request);
        $limits->ensureDataObjectRoom();

        $object = DB::transaction(function () use ($data) {
            $object = DataObject::create(collect($data)->only(['name', 'description', 'icon', 'settings'])->all());
            $this->syncFields($object, $data['fields'] ?? []);
            $this->syncRelationships($object, $data['relationships'] ?? []);

            return $object;
        });

        return response()->json(['data' => $object->load('fields', 'relationships')], 201);
    }

    public function update(Request $request, DataObject $dataObject): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $this->rules($request, $dataObject);

        DB::transaction(function () use ($dataObject, $data) {
            $dataObject->update(collect($data)->only(['name', 'description', 'icon', 'settings'])->all());
            if (isset($data['fields'])) {
                $this->syncFields($dataObject, $data['fields']);
            }
            if (isset($data['relationships'])) {
                $this->syncRelationships($dataObject, $data['relationships']);
            }
        });

        return response()->json(['data' => $dataObject->fresh()->load('fields', 'relationships')]);
    }

    public function destroy(Request $request, DataObject $dataObject): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $dataObject->delete();

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?DataObject $object = null): array
    {
        return $request->validate([
            'name' => [$object ? 'sometimes' : 'required', 'string', 'max:150', Rule::unique('data_objects', 'name')->ignore($object?->id)],
            'description' => ['nullable', 'string'], 'icon' => ['nullable', 'string', 'max:50'], 'settings' => ['nullable', 'array'],
            'fields' => ['sometimes', 'array', 'max:200'],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(['text', 'textarea', 'number', 'date', 'datetime', 'email', 'url', 'select', 'multiselect', 'checkbox', 'file'])],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.is_required' => ['boolean'], 'fields.*.is_indexed' => ['boolean'],
            'fields.*.show_in_list' => ['boolean'], 'fields.*.contact_specific' => ['boolean'],
            'relationships' => ['sometimes', 'array'],
            'relationships.*.relationship_id' => ['required', 'exists:data_object_relationships,id'],
            'relationships.*.is_exclusive' => ['boolean'], 'relationships.*.max_contacts' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function syncFields(DataObject $object, array $fields): void
    {
        $existing = $object->fields()->get()->keyBy('key');
        $keep = [];
        $needsReindex = false;

        foreach ($fields as $i => $f) {
            $before = $existing[$f['key']] ?? null;
            $field = $object->fields()->updateOrCreate(['key' => $f['key']], [
                'label' => $f['label'], 'type' => $f['type'], 'options' => $f['options'] ?? null,
                'is_required' => $f['is_required'] ?? false, 'is_indexed' => $f['is_indexed'] ?? false,
                'show_in_list' => $f['show_in_list'] ?? false, 'contact_specific' => $f['contact_specific'] ?? false,
                'position' => $i,
            ]);
            $keep[] = $field->id;

            // Existing rows must be re-indexed if a field became indexed/unindexed or its type changed.
            if ($before && ($before->is_indexed !== $field->is_indexed || ($field->is_indexed && $before->type !== $field->type))) {
                $needsReindex = true;
            }
            if (! $before && $field->is_indexed && $object->records()->exists()) {
                $needsReindex = true;
            }
        }

        $removed = $object->fields()->whereNotIn('id', $keep)->get();
        $removed->each->delete();   // typed index rows cascade; values remain in each record's JSON
        $needsReindex = $needsReindex || $removed->isNotEmpty();

        if ($needsReindex) {
            ReindexDataObject::dispatch($object->id)->afterCommit();
        }
    }

    private function syncRelationships(DataObject $object, array $rules): void
    {
        $object->relationships()->sync(collect($rules)->mapWithKeys(fn ($r) => [$r['relationship_id'] => [
            'is_exclusive' => $r['is_exclusive'] ?? false, 'max_contacts' => $r['max_contacts'] ?? null,
        ]])->all());
    }
}
