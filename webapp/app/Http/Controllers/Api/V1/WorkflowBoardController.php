<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\WorkflowBoard;
use App\Services\DataObjects\RecordQuery;
use App\Services\DataObjects\RecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Kanban "Workflow Boards": columns = choices of a select field on a data object; cards = records. */
class WorkflowBoardController extends Controller
{
    private const PER_COLUMN = 50;

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $allowed = data_get($u->permissionTemplate?->permissions, 'boards');   // null = all
        $boards = WorkflowBoard::with('dataObject:id,name')->when(! $u->isAdmin(), fn ($q) => $q->where('status', 'active'))->orderBy('name')->get()
            ->filter(fn ($b) => $u->isAdmin() || $allowed === null || in_array($b->id, (array) $allowed, true))->values();

        return response()->json(['data' => $boards]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => WorkflowBoard::create($this->validated($request))], 201);
    }

    public function update(Request $request, WorkflowBoard $board): JsonResponse
    {
        $board->update($this->validated($request));

        return response()->json(['data' => $board]);
    }

    public function destroy(Request $request, WorkflowBoard $board): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $board->delete();

        return response()->json(null, 204);
    }

    public function show(Request $request, WorkflowBoard $board): JsonResponse
    {
        $object = DataObject::with('fields')->findOrFail($board->data_object_id);
        abort_unless($request->user()->isAdmin() || $request->user()->hasPermission("data_objects.{$object->id}.view") || $request->user()->hasPermission('data_objects.view'), 403);
        $field = $object->fields->firstWhere('key', $board->key_field) ?? abort(422, 'The board\'s key field no longer exists.');
        // the columns in the order the administrator arranged them; a choice that was added to the field since comes last, one that was removed is gone
        $choices = $field->options['choices'] ?? [];
        $arranged = collect((array) $board->column_order)->filter(fn ($c) => in_array($c, $choices, true))->unique();
        $choices = $arranged->merge(collect($choices)->reject(fn ($c) => $arranged->contains($c)))->values()->all();
        $display = $object->fields->whereIn('key', $board->display_fields ?: $object->fields->where('show_in_list', true)->pluck('key')->all())->values();

        // One grouped query on the covering field index gives every column's total (instead of one COUNT per column).
        $counts = null;
        if ($field->is_indexed && $field->type === 'select') {
            // index-only: deleted records lose their index rows (DataObjectRecordController::destroy), so no join is needed
            $counts = DB::table('data_object_field_index as fi')->where('fi.field_id', $field->id)
                ->groupBy('fi.value_string')->selectRaw('fi.value_string as v, count(*) as n')->pluck('n', 'v');
            $counts[''] = max(0, DB::table('data_object_records')->where('data_object_id', $object->id)->whereNull('deleted_at')->count() - $counts->sum());
        }

        $columns = [];
        foreach (array_merge($choices, ['']) as $choice) {
            $filter = ['rules' => [['field' => $board->key_field, 'op' => $choice === '' ? 'empty' : '=', 'value' => $choice]]];
            $q = (new RecordQuery($object))->build($filter);
            $total = $counts ? (int) ($counts[$choice] ?? 0) : (clone $q)->count();
            $cards = $total === 0 ? collect() : $q->with('contacts:id,first_name,last_name')->orderByDesc('data_object_records.updated_at')->limit(self::PER_COLUMN)->get();

            // contacts with an open task get a flag (old "Has outstanding tasks")
            $contactIds = $cards->flatMap->contacts->pluck('id')->unique();
            $withTasks = $contactIds->isEmpty() ? collect() : DB::table('tasks')->whereIn('contact_id', $contactIds)->whereNull('completed_at')->distinct()->pluck('contact_id');

            if ($choice === '' && $total === 0) {
                continue;   // hide the "no value" column when empty
            }
            $columns[] = [
                'key' => $choice, 'label' => $choice === '' ? 'No value' : $choice, 'color' => $board->colors[$choice] ?? null, 'total' => $total,
                'cards' => $cards->map(fn ($r) => [
                    'id' => $r->id,
                    'values' => $display->mapWithKeys(fn ($f) => [$f->key => $r->data[$f->key] ?? null])->all(),
                    'contacts' => $r->contacts->filter(fn ($c) => ! $board->relationship_id || (int) $c->pivot->relationship_id === (int) $board->relationship_id)
                        ->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name, 'has_tasks' => $withTasks->contains($c->id)])->values(),
                ])->values(),
            ];
        }

        return response()->json(['board' => $board->only(['id', 'name', 'description']), 'object' => ['id' => $object->id, 'name' => $object->name],
            'display' => $display->map(fn ($f) => ['key' => $f->key, 'label' => $f->label])->values(), 'columns' => $columns]);
    }

    /** Drag a card to another column = set the key field's value (also fires data-object automations). */
    public function move(Request $request, WorkflowBoard $board, DataObjectRecord $record, RecordService $records): JsonResponse
    {
        abort_unless($record->data_object_id === $board->data_object_id, 404);
        abort_unless($request->user()->isAdmin() || $request->user()->hasPermission("data_objects.{$board->data_object_id}.edit") || $request->user()->hasPermission('data_objects.edit'), 403);
        $to = $request->validate(['to' => ['present', 'nullable', 'string']])['to'];

        $records->update($record, [$board->key_field => $to === '' ? null : $to]);

        return response()->json(['data' => $record->only(['id', 'data'])]);
    }

    private function validated(Request $request): array
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'],
            'data_object_id' => ['required', 'exists:data_objects,id'],
            'key_field' => ['required', 'string'],
            'relationship_id' => ['nullable', 'exists:data_object_relationships,id'],
            'display_fields' => ['nullable', 'array', 'max:8'], 'display_fields.*' => ['string'],
            'colors' => ['nullable', 'array'], 'colors.*' => ['regex:/^#[0-9a-fA-F]{6}$/'],
            'column_order' => ['nullable', 'array', 'max:200'], 'column_order.*' => ['string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $field = DataObject::findOrFail($d['data_object_id'])->fields()->where('key', $d['key_field'])->first();
        abort_if(! $field || $field->type !== 'select', 422, 'The key field must be a dropdown field of the chosen data object.');

        return $d;
    }
}
