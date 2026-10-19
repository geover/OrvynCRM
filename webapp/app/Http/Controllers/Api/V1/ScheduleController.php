<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\Schedule;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin "Scheduler" definitions (which data-object date fields appear on the calendar) +
 * the calendar feed the Schedules page reads (user's tasks + scheduled data-object items).
 */
class ScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Schedule::with('dataObject:id,name')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Schedule::create($this->validated($request))], 201);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $schedule->update($this->validated($request));

        return response()->json(['data' => $schedule]);
    }

    public function destroy(Request $request, Schedule $schedule): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $schedule->delete();

        return response()->json(null, 204);
    }

    /** GET /calendar?from=2026-10-01&to=2026-10-31 */
    public function calendar(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($d['from'])->startOfDay();
        $to = Carbon::parse($d['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 62, 422, 'Range too large.');
        $user = $request->user();

        $events = Task::with('contact:id,first_name,last_name')->where('assigned_to', $user->id)->whereBetween('due_at', [$from, $to])->get()
            ->map(fn ($t) => ['kind' => 'task', 'id' => $t->id, 'title' => $t->title, 'at' => $t->due_at->toIso8601String(), 'done' => (bool) $t->completed_at,
                'overdue' => ! $t->completed_at && $t->due_at->isPast(), 'description' => $t->description, 'priority' => $t->priority, 'completed_at' => $t->completed_at?->toIso8601String(), 'created_at' => $t->created_at?->toIso8601String(), 'contact' => $t->contact ? ['id' => $t->contact->id, 'name' => $t->contact->full_name] : null])->all();

        foreach (Schedule::with('dataObject.fields')->get() as $s) {
            $field = $s->dataObject->fields->firstWhere('key', $s->start_field);
            if (! $field) {
                continue;
            }
            // Records the user may see: linked to the user's own contact via the schedule's user relationship (if configured).
            $q = DataObjectRecord::where('data_object_records.data_object_id', $s->data_object_id)->with('contacts:id,first_name,last_name');
            if ($s->user_relationship_id && $user->contact_id) {
                $q->whereIn('data_object_records.id', DB::table('data_object_record_contact')->where('contact_id', $user->contact_id)
                    ->where('relationship_id', $s->user_relationship_id)->select('record_id'));
            }
            // date fields are stored as ISO strings in JSON; the indexed copy gives a real range scan.
            if ($field->is_indexed) {
                $q->whereIn('data_object_records.id', DB::table('data_object_field_index')->where('field_id', $field->id)->whereBetween('value_date', [$from, $to])->select('record_id'));
            } else {
                $q->whereBetween('data_object_records.data->'.$s->start_field, [$from->toDateTimeString(), $to->toDateTimeString()]);
            }
            foreach ($q->limit(500)->get() as $r) {
                $events[] = ['kind' => 'item', 'id' => $r->id, 'schedule' => $s->name, 'data_object_id' => $s->data_object_id, 'object' => $s->dataObject->name,
                    'title' => $this->title($r, $s->dataObject), 'at' => date('c', strtotime($r->data[$s->start_field])), 'end' => $s->end_field && ! empty($r->data[$s->end_field]) ? date('c', strtotime($r->data[$s->end_field])) : null,
                    'contact' => ($c = $r->contacts->first()) ? ['id' => $c->id, 'name' => $c->full_name] : null];
            }
        }

        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return response()->json(['data' => $events]);
    }

    private function title(DataObjectRecord $r, DataObject $o): string
    {
        $first = $o->fields->firstWhere('show_in_list', true) ?? $o->fields->first();

        return (string) ($first ? ($r->data[$first->key] ?? "{$o->name} #{$r->id}") : "{$o->name} #{$r->id}");
    }

    private function validated(Request $request): array
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'],
            'data_object_id' => ['required', 'exists:data_objects,id'],
            'contact_relationship_id' => ['nullable', 'exists:data_object_relationships,id'],
            'user_relationship_id' => ['nullable', 'exists:data_object_relationships,id'],
            'start_field' => ['required', 'string'], 'end_field' => ['nullable', 'string'],
            'contact_specific' => ['boolean'], 'type' => ['required', Rule::in(['date', 'datetime'])],
        ]);
        $fields = DataObject::findOrFail($d['data_object_id'])->fields()->whereIn('type', ['date', 'datetime'])->pluck('key');
        abort_unless($fields->contains($d['start_field']) && (empty($d['end_field']) || $fields->contains($d['end_field'])), 422, 'Start/end must be date fields of the chosen data object.');

        return $d;
    }
}
