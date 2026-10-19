<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Widget;
use App\Services\Queries\QueryActions;
use App\Services\Queries\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Dashboard query widgets: a saved query shown as a card, with CSV export and batch email / SMS. */
class WidgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $allowed = data_get($u->permissionTemplate?->permissions, 'widgets');   // null = all

        $widgets = Widget::with('savedQuery:id,name,scope')
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $u->id))
            ->orderBy('position')->get()
            ->filter(fn ($w) => $u->isAdmin() || $w->user_id === $u->id || $allowed === null || in_array($w->id, (array) $allowed, true))
            ->values();

        return response()->json(['data' => $widgets]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $shared = ($d['shared'] ?? false) && $request->user()->isAdmin();
        $w = Widget::create(collect($d)->except('shared')->all() + ['type' => $d['type'] ?? 'query', 'user_id' => $shared ? null : $request->user()->id, 'position' => (Widget::max('position') ?? 0) + 1]);

        return response()->json(['data' => $w->load('savedQuery:id,name,scope')], 201);
    }

    public function update(Request $request, Widget $widget): JsonResponse
    {
        $this->authorizeWrite($request, $widget);
        $widget->update(collect($this->validated($request))->except('shared')->all());

        return response()->json(['data' => $widget->load('savedQuery:id,name,scope')]);
    }

    public function destroy(Request $request, Widget $widget): JsonResponse
    {
        $this->authorizeWrite($request, $widget);
        $widget->delete();

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        foreach ($ids as $pos => $id) {
            Widget::whereKey($id)->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhereRaw($request->user()->isAdmin() ? '1=1' : '1=0'))->update(['position' => $pos]);
        }

        return response()->json(null, 204);
    }

    public function data(Request $request, Widget $widget, QueryRunner $runner, QueryActions $actions): JsonResponse
    {
        $q = $widget->savedQuery ?? abort(404, 'The query behind this widget was deleted.');

        $view = $request->validate(['sort' => ['nullable', 'string', 'max:100'], 'dir' => ['nullable', 'in:asc,desc'], 'search' => ['nullable', 'string', 'max:200']]);
        $definition = $actions->narrowed($request, (array) $q->definition);

        return response()->json($runner->run($q->scope, $definition, min($request->integer('limit', 10), 100), max($request->integer('offset', 0), 0), $view) + ['query' => $q->only(['id', 'name', 'scope'])]);
    }

    public function export(Request $request, Widget $widget, QueryActions $actions): StreamedResponse
    {
        $q = $widget->savedQuery ?? abort(404);

        return $actions->csv($request, $q->scope, (array) $q->definition, $widget->name);
    }

    /** Email or text every contact in the widget's result (queued in chunks; respects opt-outs & suppression). */
    public function batch(Request $request, Widget $widget, string $channel, QueryActions $actions): JsonResponse
    {
        $q = $widget->savedQuery ?? abort(404);

        return $actions->batch($request, $channel, $q->scope, (array) $q->definition, $q->id, $widget->name);
    }

    private function authorizeWrite(Request $request, Widget $widget): void
    {
        abort_unless($request->user()->isAdmin() || $widget->user_id === $request->user()->id, 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'], 'type' => ['sometimes', 'string', 'max:30'],
            'saved_query_id' => ['required', 'exists:saved_queries,id'], 'settings' => ['nullable', 'array'], 'shared' => ['boolean'],
        ]);
    }
}
