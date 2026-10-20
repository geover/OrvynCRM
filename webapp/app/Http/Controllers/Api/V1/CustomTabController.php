<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomTab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin "Custom tabs": tabs on every contact record, and pages in the main left-hand menu, that embed a URL or static HTML. */
class CustomTabController extends Controller
{
    public const ICONS = ['globe', 'file-text', 'book-open', 'layout-dashboard', 'bar-chart', 'calendar', 'link', 'briefcase', 'graduation-cap', 'life-buoy', 'shopping-cart', 'wrench', 'star', 'megaphone', 'map', 'inbox'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tabs = CustomTab::orderBy('position')->get();

        // administrators get everything (they edit it); everybody else only the tabs meant for them, and without the content
        return response()->json(['data' => $user->isAdmin() ? $tabs : $tabs->filter(fn ($t) => $t->isFor($user))->map(fn ($t) => $t->only(['id', 'title', 'description', 'type', 'placement', 'icon']))->values()]);
    }

    public function show(Request $request, CustomTab $customTab): JsonResponse
    {
        abort_unless($customTab->isFor($request->user()), 404);

        return response()->json(['data' => $customTab->only(['id', 'title', 'description', 'type', 'content', 'placement', 'icon'])]);
    }

    /** Replace the whole ordered list in one call (the admin screen edits the list as a unit). */
    public function sync(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'items' => ['present', 'array', 'max:30'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.title' => ['required', 'string', 'max:80'],
            'items.*.description' => ['nullable', 'string', 'max:300'],
            'items.*.type' => ['required', Rule::in(['iframe', 'html'])],
            'items.*.content' => ['required', 'string', 'max:100000'],
            'items.*.placement' => ['sometimes', Rule::in(CustomTab::PLACEMENTS)],
            'items.*.icon' => ['nullable', Rule::in(self::ICONS)],
            'items.*.audience' => ['nullable', 'array'],
            'items.*.audience.roles' => ['nullable', 'array'], 'items.*.audience.roles.*' => [Rule::in(['admin', 'manager', 'user'])],
            'items.*.audience.templates' => ['nullable', 'array'], 'items.*.audience.templates.*' => ['integer'],
            'items.*.audience.users' => ['nullable', 'array'], 'items.*.audience.users.*' => ['integer'],
        ]);

        foreach ($d['items'] as $i) {
            if ($i['type'] === 'iframe' && ! preg_match('#^https://#i', $i['content'])) {
                return response()->json(['message' => "“{$i['title']}”: embedded pages must use an https:// URL.", 'errors' => ['items' => ['https required']]], 422);
            }
            if (array_key_exists('audience', $i) && $i['audience'] !== null && ! collect($i['audience'])->flatten()->filter()->count()) {
                return response()->json(['message' => "“{$i['title']}”: choose who can see it (a role, a group or a person), or let everybody see it.", 'errors' => ['items' => ['audience required']]], 422);
            }
        }

        $keep = [];
        foreach ($d['items'] as $pos => $i) {
            $audience = $i['audience'] ?? null;
            $keep[] = CustomTab::updateOrCreate(['id' => $i['id'] ?? null], [
                'title' => $i['title'], 'description' => filled($i['description'] ?? null) ? trim($i['description']) : null, 'type' => $i['type'], 'content' => $i['content'], 'position' => $pos,
                'placement' => $i['placement'] ?? 'contact', 'icon' => $i['icon'] ?? null,
                'audience' => $audience ? ['roles' => array_values($audience['roles'] ?? []), 'templates' => array_map('intval', $audience['templates'] ?? []), 'users' => array_map('intval', $audience['users'] ?? [])] : null,
            ])->id;
        }
        CustomTab::whereNotIn('id', $keep)->delete();

        return $this->index($request);
    }
}
