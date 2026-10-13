<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $base = Task::query()->with('contact:id,first_name,last_name', 'assignee:id,name');
        $user = $request->user();
        if (! $user->isAdmin()) {
            $scope = data_get($user->permissionTemplate?->permissions, 'tasks.scope', 'own');
            if ($scope === 'own') {
                $base->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id));
            } elseif ($scope === 'specific') {
                $base->where(fn ($q) => $q->whereIn('assigned_to', (array) data_get($user->permissionTemplate->permissions, 'tasks.users', []))->orWhere('assigned_to', $user->id));
            }
        }

        return QueryBuilder::for($base)
            ->allowedFilters(
                AllowedFilter::exact('assigned_to'), AllowedFilter::exact('contact_id'), AllowedFilter::exact('type'),
                AllowedFilter::callback('status', fn ($q, $v) => $v === 'open' ? $q->open() : ($v === 'overdue' ? $q->overdue() : $q->whereNotNull('completed_at'))),
                AllowedFilter::callback('mine', fn ($q) => $q->where('assigned_to', $request->user()->id)),
            )
            ->allowedSorts('due_at', 'created_at', 'priority')
            ->defaultSort('due_at')
            ->orderBy('tasks.id')                                                   // tie-breaker for cursor paging
            ->cursorPaginate(min((int) $request->integer('per_page', 25), 100));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'exists:contacts,id'], 'assigned_to' => ['nullable', 'exists:users,id'],
            'type' => ['nullable', 'string', 'max:50'], 'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'], 'priority' => ['nullable', 'in:low,normal,high'],
            'due_at' => ['nullable', 'date'],
        ]);

        // array_merge (not +): an explicit null assigned_to from the form must fall back to the current user
        $task = Task::create(array_merge($data, ['created_by' => $request->user()->id, 'assigned_to' => $data['assigned_to'] ?? $request->user()->id]));

        return response()->json(['data' => $task], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'exists:users,id'], 'priority' => ['sometimes', 'in:low,normal,high'],
            'due_at' => ['nullable', 'date'], 'completed' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('completed', $data)) {
            $task->completed_at = $data['completed'] ? now() : null;
            unset($data['completed']);
        }
        $task->fill($data)->save();

        return response()->json(['data' => $task]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(null, 204);
    }
}
