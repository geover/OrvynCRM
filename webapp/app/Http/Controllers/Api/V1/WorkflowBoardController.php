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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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

        if ($request->boolean('stats')) {                                       // the boards page: how the items of each board spread over its columns
            $boards->each(fn ($b) => $b->setAttribute('stats', $this->stats($b, $u)));
        }

        return response()->json(['data' => $boards]);
    }

    /**
     * The columns of a board with how many items are in each, and when something on it last changed. Null when the person may not see the items.
     *
     * @return array{total: int, columns: list<array{key: string, label: string, color: ?string, total: int}>, updated_at: ?string}|null
     */
    private function stats(WorkflowBoard $board, $user): ?array
    {
        $object = DataObject::with('fields')->find($board->data_object_id);
        $field = $object?->fields->firstWhere('key', $board->key_field);
        if (! $object || ! $field || ! ($user->isAdmin() || $user->hasPermission("data_objects.{$object->id}.view") || $user->hasPermission('data_objects.view'))) {
            return null;
        }
        $choices = $field->options['choices'] ?? [];
        $arranged = collect((array) $board->column_order)->filter(fn ($c) => in_array($c, $choices, true))->unique();
        $choices = $arranged->merge(collect($choices)->reject(fn ($c) => $arranged->contains($c)))->values()->all();

        $all = DB::table('data_object_records')->where('data_object_id', $object->id)->whereNull('deleted_at');
        $everything = (clone $all)->count();
        $counts = $field->is_indexed && $field->type === 'select'
            ? DB::table('data_object_field_index')->where('field_id', $field->id)->groupBy('value_string')->selectRaw('value_string as v, count(*) as n')->pluck('n', 'v')
            : collect($choices)->mapWithKeys(fn ($c) => [$c => (new RecordQuery($object))->build(['rules' => [['field' => $board->key_field, 'op' => '=', 'value' => $c]]])->count()]);

        $columns = collect($choices)->map(fn ($c) => ['key' => $c, 'label' => $c, 'color' => $board->colors[$c] ?? null, 'total' => (int) ($counts[$c] ?? 0)]);
        $rest = max(0, $everything - $columns->sum('total'));
        if ($rest > 0) {
            $columns->push(['key' => '', 'label' => 'No value', 'color' => null, 'total' => $rest]);
        }

        return ['total' => $everything, 'columns' => $columns->values()->all(), 'updated_at' => ($last = (clone $all)->max('updated_at')) ? Carbon::parse($last)->toIso8601String() : null];
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

        // What the person narrowed the board down to: a search, their own conditions (the same builder as the item lists), when the card last changed, and whether it has contacts.
        $search = trim($request->string('q')->toString());
        $conditions = (array) $request->input('filter', []);
        $updated = $request->string('updated')->toString();
        $withContacts = $request->string('contacts')->toString();
        $narrowed = $search !== '' || ! empty($conditions['rules']) || in_array($updated, ['today', '7d', '30d'], true) || in_array($withContacts, ['with', 'without'], true);

        // One grouped query on the covering field index gives every column's total (instead of one COUNT per column).
        $counts = null;
        if (! $narrowed && $field->is_indexed && $field->type === 'select') {
            // index-only: deleted records lose their index rows (DataObjectRecordController::destroy), so no join is needed
            $counts = DB::table('data_object_field_index as fi')->where('fi.field_id', $field->id)
                ->groupBy('fi.value_string')->selectRaw('fi.value_string as v, count(*) as n')->pluck('n', 'v');
            $counts[''] = max(0, DB::table('data_object_records')->where('data_object_id', $object->id)->whereNull('deleted_at')->count() - $counts->sum());
        }

        $sumField = $display->firstWhere('type', 'number');                  // the first number on the cards is added up per column

        $columns = [];
        foreach (array_merge($choices, ['']) as $choice) {
            $rules = [['field' => $board->key_field, 'op' => $choice === '' ? 'empty' : '=', 'value' => $choice]];
            if (! empty($conditions['rules'])) {
                $rules[] = $conditions;                                      // the person's conditions are a group of their own: "any of" stays inside it
            }
            try {
                $q = (new RecordQuery($object))->build(['rules' => $rules], $search);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $q->when(in_array($updated, ['today', '7d', '30d'], true), fn ($w) => $w->where('data_object_records.updated_at', '>=', match ($updated) {
                'today' => now()->startOfDay(), '7d' => now()->subDays(7), default => now()->subDays(30),
            }));
            $q->when(in_array($withContacts, ['with', 'without'], true), fn ($w) => $withContacts === 'with'
                ? $w->whereIn('data_object_records.id', fn ($s) => $s->select('record_id')->from('data_object_record_contact'))
                : $w->whereNotIn('data_object_records.id', fn ($s) => $s->select('record_id')->from('data_object_record_contact')));
            $total = $counts ? (int) ($counts[$choice] ?? 0) : (clone $q)->count();
            $cards = $total === 0 ? collect() : $q->with('contacts:id,first_name,last_name')->orderByDesc('data_object_records.updated_at')->limit(self::PER_COLUMN)->get();

            // contacts with an open task get a flag (old "Has outstanding tasks")
            $contactIds = $cards->flatMap->contacts->pluck('id')->unique();
            $withTasks = $contactIds->isEmpty() ? collect() : DB::table('tasks')->whereIn('contact_id', $contactIds)->whereNull('completed_at')->distinct()->pluck('contact_id');

            if ($choice === '' && $total === 0) {
                continue;   // hide the "no value" column when empty
            }            // the column's worth, when it can be added up from every card in it (a column with more cards than are shown gives no total)
            $sum = $sumField && $cards->count() === $total ? round($cards->sum(fn ($r) => (float) ($r->data[$sumField->key] ?? 0)), 2) : null;
            $columns[] = [
                'key' => $choice, 'label' => $choice === '' ? 'No value' : $choice, 'color' => $board->colors[$choice] ?? null, 'total' => $total, 'sum' => $sum,
                'cards' => $cards->map(fn ($r) => [
                    'id' => $r->id,
                    'values' => $display->mapWithKeys(fn ($f) => [$f->key => $r->data[$f->key] ?? null])->all(),
                    'updated_at' => $r->updated_at?->toIso8601String(),
                    'contacts' => $r->contacts->filter(fn ($c) => ! $board->relationship_id || (int) $c->pivot->relationship_id === (int) $board->relationship_id)
                        ->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name, 'has_tasks' => $withTasks->contains($c->id)])->values(),
                ])->values(),
            ];
        }

        $can = fn (string $ability) => $request->user()->isAdmin() || $request->user()->hasPermission("data_objects.{$object->id}.$ability") || $request->user()->hasPermission("data_objects.$ability");

        return response()->json(['board' => $board->only(['id', 'name', 'description', 'key_field']), 'object' => ['id' => $object->id, 'name' => $object->name],
            'display' => $display->map(fn ($f) => ['key' => $f->key, 'label' => $f->label, 'type' => $f->type])->values(), 'sum_field' => $sumField?->only(['key', 'label']),
            'fields' => $object->fields->map(fn ($f) => ['key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'options' => $f->options])->values(),
            'narrowed' => $narrowed, 'can' => ['edit' => $can('edit'), 'delete' => $can('delete')], 'columns' => $columns]);
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
