<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomTab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin "Custom Tabs": extra tabs on the contact record that embed a URL or static HTML. */
class CustomTabController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user()->isAdmin();

        return response()->json(['data' => CustomTab::orderBy('position')->get($admin ? ['*'] : ['id', 'title', 'type'])]);
    }

    public function show(CustomTab $customTab): JsonResponse
    {
        return response()->json(['data' => $customTab->only(['id', 'title', 'type', 'content'])]);
    }

    /** Replace the whole ordered list in one call (the admin screen edits the list as a unit). */
    public function sync(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'items' => ['present', 'array', 'max:30'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.title' => ['required', 'string', 'max:80'],
            'items.*.type' => ['required', Rule::in(['iframe', 'html'])],
            'items.*.content' => ['required', 'string', 'max:100000'],
        ]);

        foreach ($d['items'] as $i) {
            if ($i['type'] === 'iframe' && ! preg_match('#^https://#i', $i['content'])) {
                return response()->json(['message' => "“{$i['title']}”: embedded pages must use an https:// URL.", 'errors' => ['items' => ['https required']]], 422);
            }
        }

        $keep = [];
        foreach ($d['items'] as $pos => $i) {
            $keep[] = CustomTab::updateOrCreate(['id' => $i['id'] ?? null], ['title' => $i['title'], 'type' => $i['type'], 'content' => $i['content'], 'position' => $pos])->id;
        }
        CustomTab::whereNotIn('id', $keep)->delete();

        return $this->index($request);
    }
}
