<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataObject;
use App\Models\SavedQuery;
use App\Services\Queries\ContactQuery;
use App\Services\Queries\QueryActions;
use App\Services\Queries\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Query Builder (admin) + Advanced Search (everyone can run saved queries and ad-hoc filters). */
class SavedQueryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => SavedQuery::with('creator:id,name')->orderBy('name')->get()]);
    }

    /** Everything the builder UI needs: contact fields + each object's fields and relationships. */
    public function meta(): JsonResponse
    {
        return response()->json([
            'contact_fields' => ContactQuery::FIELDS,
            'objects' => DataObject::with(['fields:id,data_object_id,key,label,type,options', 'relationships:id,label'])->orderBy('name')->get(['id', 'name'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'fields' => $o->fields->map->only(['key', 'label', 'type', 'options'])->values(), 'relationships' => $o->relationships->map->only(['id', 'label'])->values()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $q = SavedQuery::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => $q], 201);
    }

    public function update(Request $request, SavedQuery $savedQuery): JsonResponse
    {
        $savedQuery->update($this->validated($request));

        return response()->json(['data' => $savedQuery]);
    }

    public function destroy(Request $request, SavedQuery $savedQuery): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $savedQuery->delete();

        return response()->json(null, 204);
    }

    public function run(Request $request, SavedQuery $savedQuery, QueryRunner $runner): JsonResponse
    {
        return $this->execute($request, $runner, $savedQuery->scope, $savedQuery->definition);
    }

    /** Ad-hoc run (preview in the builder / advanced search) without saving. */
    /** Download what a query (saved, or described in the request) finds. */
    public function export(Request $request, QueryActions $actions): StreamedResponse
    {
        $q = $actions->resolve($request);

        return $actions->csv($request, $q['scope'], $q['definition'], $q['name']);
    }

    /** E-mail or text everyone a query (saved, or described in the request) finds. */
    public function batch(Request $request, string $channel, QueryActions $actions): JsonResponse
    {
        $q = $actions->resolve($request);

        return $actions->batch($request, $channel, $q['scope'], $q['definition'], $q['saved'], $q['saved'] ? $q['name'] : 'a search');
    }

    public function preview(Request $request, QueryRunner $runner): JsonResponse
    {
        $d = $request->validate(['scope' => ['required', Rule::in(['contact', 'data_object'])], 'definition' => ['present', 'array']]);

        return $this->execute($request, $runner, $d['scope'], $d['definition']);
    }

    private function execute(Request $request, QueryRunner $runner, string $scope, array $definition): JsonResponse
    {
        try {
            $r = $runner->run($scope, $definition, min($request->integer('limit', 25), 200), max($request->integer('offset', 0), 0));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($r);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'scope' => ['required', Rule::in(['contact', 'data_object'])],
            'data_object_id' => ['nullable', 'required_if:scope,data_object', 'exists:data_objects,id'],
            'definition' => ['present', 'array'],
            'definition.filter' => ['nullable', 'array'], 'definition.contact_filter' => ['nullable', 'array'],
            'definition.columns' => ['nullable', 'array'], 'definition.relationship_ids' => ['nullable', 'array'],
        ]);
        $d['definition'] ??= [];     // an empty definition means "no filters"; validate() omits empty arrays
        if ($d['scope'] === 'data_object') {
            $d['definition']['data_object_id'] = (int) $d['data_object_id'];
        }

        return $d;
    }
}
