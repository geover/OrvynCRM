<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\QueueBatchMessages;
use App\Models\Widget;
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

    public function data(Request $request, Widget $widget, QueryRunner $runner): JsonResponse
    {
        $q = $widget->savedQuery ?? abort(404, 'The query behind this widget was deleted.');

        return response()->json($runner->run($q->scope, $q->definition, min($request->integer('limit', 10), 100), max($request->integer('offset', 0), 0)) + ['query' => $q->only(['id', 'name', 'scope'])]);
    }

    public function export(Request $request, Widget $widget, QueryRunner $runner): StreamedResponse
    {
        $q = $widget->savedQuery ?? abort(404);
        abort_unless($request->user()->hasPermission('contacts.view'), 403);

        return response()->streamDownload(function () use ($q, $runner) {
            $out = fopen('php://output', 'w');
            $first = $runner->run($q->scope, $q->definition, 1, 0);
            fputcsv($out, array_column($first['columns'], 'label'));
            // page through the result so memory stays flat on huge queries
            for ($offset = 0; $offset < $first['total']; $offset += 500) {
                foreach ($runner->run($q->scope, $q->definition, 500, $offset)['rows'] as $row) {
                    fputcsv($out, array_map(fn ($v) => $this->csvSafe(is_array($v) ? implode('; ', $v) : $v), array_values($row['values'])));
                }
            }
            fclose($out);
        }, str($widget->name)->slug().'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Email or text every contact in the widget's result (queued in chunks; respects opt-outs & suppression). */
    public function batch(Request $request, Widget $widget, string $channel): JsonResponse
    {
        abort_unless(in_array($channel, ['email', 'sms'], true), 404);
        $q = $widget->savedQuery ?? abort(404);
        $spec = $request->validate($channel === 'email'
            ? ['subject' => ['required', 'string', 'max:255'], 'html' => ['required', 'string', 'max:500000']]
            : ['body' => ['required', 'string', 'max:1600']]);

        QueueBatchMessages::dispatch($q->id, $channel, $spec, $request->user()->id);

        return response()->json(['queued' => true], 202);
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

    /** Neutralise spreadsheet formula injection in exported cells. */
    private function csvSafe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
