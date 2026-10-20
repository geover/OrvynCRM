<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Note;
use App\Models\Task;
use App\Support\SafeHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class TaskController extends Controller
{
    /** The tasks this person may see (all of them for an administrator; otherwise by their permission template). */
    private function visible(Request $request): Builder
    {
        $base = Task::query();
        $user = $request->user();
        if (! $user->isAdmin()) {
            $scope = data_get($user->permissionTemplate?->permissions, 'tasks.scope', 'own');
            if ($scope === 'own') {
                $base->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id));
            } elseif ($scope === 'specific') {
                $base->where(fn ($q) => $q->whereIn('assigned_to', (array) data_get($user->permissionTemplate->permissions, 'tasks.users', []))->orWhere('assigned_to', $user->id));
            }
        }

        return $base;
    }

    /** The numbers above the My tasks page: what is open, due today, overdue and done, for the person's own tasks (`?mine=1`) or everything they may see. */
    public function summary(Request $request): JsonResponse
    {
        $base = $this->visible($request);
        $request->boolean('mine') && $base->where('assigned_to', $request->user()->id);
        [$start, $end] = [today(), today()->addDay()];

        $row = $base->selectRaw(
            'coalesce(sum(case when completed_at is null then 1 else 0 end), 0) as open_count,
             coalesce(sum(case when completed_at is null and due_at >= ? and due_at < ? then 1 else 0 end), 0) as today_count,
             coalesce(sum(case when completed_at is null and due_at < ? then 1 else 0 end), 0) as overdue_count,
             coalesce(sum(case when completed_at >= ? then 1 else 0 end), 0) as done_today,
             coalesce(sum(case when completed_at >= ? then 1 else 0 end), 0) as done_7d,
             coalesce(sum(case when completed_at is null and priority = ? then 1 else 0 end), 0) as high_open',
            [$start, $end, now(), $start, now()->subDays(7), 'high'],
        )->first();

        return response()->json(['data' => [
            'open' => (int) $row->open_count, 'today' => (int) $row->today_count, 'overdue' => (int) $row->overdue_count,
            'done_today' => (int) $row->done_today, 'done_7d' => (int) $row->done_7d, 'high' => (int) $row->high_open,
        ]]);
    }

    public function index(Request $request)
    {
        $base = $this->visible($request)->with('contact:id,first_name,last_name', 'assignee:id,name', 'note:id,title', 'attachments:id,note_id,task_id,title,mime,size,url,created_at');

        $query = QueryBuilder::for($base)
            ->allowedFilters(
                AllowedFilter::exact('assigned_to'), AllowedFilter::exact('contact_id'), AllowedFilter::exact('type'),
                AllowedFilter::callback('status', fn ($q, $v) => match ($v) {
                    'open' => $q->open(),
                    'overdue' => $q->overdue(),
                    'today' => $q->open()->whereDate('due_at', today()),
                    'all' => $q,
                    default => $q->whereNotNull('completed_at'),
                }),
                AllowedFilter::callback('mine', fn ($q) => $q->where('assigned_to', $request->user()->id)),
                AllowedFilter::exact('priority'),
                AllowedFilter::callback('q', function ($q, $v) {
                    $like = '%'.addcslashes((string) $v, '%_\\').'%';
                    $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like)
                        ->orWhereHas('contact', fn ($c) => $c->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)));
                }),
            )
            ->allowedSorts(
                'created_at', 'title',
                AllowedSort::callback('due_at', fn ($q, bool $desc) => ($request->has('page') ? $q->orderByRaw('due_at is null') : $q)->orderBy('due_at', $desc ? 'desc' : 'asc')),       // no date: last
                AllowedSort::callback('priority', fn ($q, bool $desc) => $request->has('page') ? $q->orderByRaw("case priority when 'high' then 3 when 'normal' then 2 else 1 end ".($desc ? 'desc' : 'asc')) : $q->orderBy('priority', $desc ? 'desc' : 'asc')),
            )
            ->defaultSort('due_at')
            ->orderBy('tasks.id');                                                  // tie-breaker for paging
        $perPage = min((int) $request->integer('per_page', 25), 100);

        // `?page=N` gives numbered pages with totals (the My tasks table); without it, cursor pagination as before
        if ($request->has('page')) {
            $p = $query->paginate($perPage);

            return response()->json(['data' => $p->items(), 'meta' => [
                'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'from' => $p->firstItem(), 'to' => $p->lastItem(),
            ]]);
        }

        return $query->cursorPaginate($perPage);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'exists:contacts,id'], 'assigned_to' => ['nullable', 'exists:users,id'],
            'type' => ['nullable', 'string', 'max:50'], 'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'], 'priority' => ['nullable', 'in:low,normal,high'],
            'due_at' => ['nullable', 'date'], 'note_id' => ['nullable', 'exists:notes,id'],
        ]);
        $data = $this->withNote($data);
        $data['description'] = SafeHtml::clean($data['description'] ?? null);              // formatted text is kept safe

        // array_merge (not +): an explicit null assigned_to from the form must fall back to the current user
        $task = Task::create(array_merge($data, ['created_by' => $request->user()->id, 'assigned_to' => $data['assigned_to'] ?? $request->user()->id]));

        return response()->json(['data' => $task->load('note:id,title', 'attachments:id,note_id,task_id,title,mime,size,url,created_at')], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'exists:users,id'], 'priority' => ['sometimes', 'in:low,normal,high'],
            'due_at' => ['nullable', 'date'], 'completed' => ['sometimes', 'boolean'],
            'note_id' => ['nullable', 'exists:notes,id'], 'completion_note' => ['nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('note_id', $data)) {
            $data = $this->withNote($data, $task);
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = SafeHtml::clean($data['description']);
        }
        $completing = false;
        if (array_key_exists('completed', $data)) {
            $completing = $data['completed'] && $task->completed_at === null;
            $task->completed_at = $data['completed'] ? ($task->completed_at ?? now()) : null;
        }
        $comment = $data['completion_note'] ?? null;
        unset($data['completed'], $data['completion_note']);
        $task->fill($data)->save();
        $completing && $this->logCompletion($request, $task, $comment);

        return response()->json(['data' => $task->load('note:id,title', 'attachments:id,note_id,task_id,title,mime,size,url,created_at')]);
    }

    /**
     * A task can be about a note. The note has to be on the same contact as the task; a task without a contact takes the note's.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withNote(array $data, ?Task $task = null): array
    {
        if (empty($data['note_id'])) {
            return $data;
        }
        $note = Note::findOrFail($data['note_id']);
        $contactId = array_key_exists('contact_id', $data) ? $data['contact_id'] : $task?->contact_id;
        abort_if($contactId && (int) $contactId !== (int) $note->contact_id, 422, 'That note belongs to another contact.');
        if (! $contactId) {
            $data['contact_id'] = $note->contact_id;                       // the task takes the note's contact
        }

        return $data;
    }

    /** Completing a task of a contact writes it down on the contact: a note that says who did it, linked to the task. */
    private function logCompletion(Request $request, Task $task, ?string $comment): void
    {
        if (! $task->contact_id) {
            return;
        }
        $user = $request->user();
        $note = Note::create([
            'contact_id' => $task->contact_id, 'user_id' => $user->id, 'task_id' => $task->id, 'tags' => ['task'],
            'title' => 'Task completed: '.$task->title,
            'body' => trim("{$user->name} completed the task “{$task->title}”.\n\n".($comment ?? '')),
        ]);
        Activity::create([
            'contact_id' => $task->contact_id, 'causer_id' => $user->id, 'type' => 'note.created',
            'subject_type' => Note::class, 'subject_id' => $note->id, 'data' => ['title' => $note->title],
        ]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(null, 204);
    }
}
