<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PermissionTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * User permission templates. Shape of `permissions` (all keys optional):
 *  contacts:      ["view","create","edit","delete","merge"]
 *  data_objects:  {"<id>": ["view","create","edit","delete"]}   (or a flat list = applies to all objects)
 *  tabs:          {"notes":true,"communication":true,"custom:<id>":true}
 *  tasks:         {"scope":"own|everyone|specific","users":[ids]}
 *  widgets:       [widget ids]      boards: [board ids]      email_template_groups: ["Group A"]
 *  other:         {"edit_if_other_user_connected":true, ...}
 *  guides:        {"general":"markdown","sidebar_tabs":"markdown",...}
 */
class PermissionTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => PermissionTemplate::withCount('users')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => PermissionTemplate::create($this->validated($request))], 201);
    }

    public function update(Request $request, PermissionTemplate $permissionTemplate): JsonResponse
    {
        $permissionTemplate->update($this->validated($request, $permissionTemplate));

        return response()->json(['data' => $permissionTemplate]);
    }

    public function destroy(Request $request, PermissionTemplate $permissionTemplate): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($permissionTemplate->users()->exists()) {
            return response()->json(['message' => 'This template is assigned to users. Reassign them first.'], 422);
        }
        $permissionTemplate->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?PermissionTemplate $t = null): array
    {
        abort_unless($request->user()->isAdmin(), 403);

        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('permission_templates', 'name')->ignore($t?->id)],
            'description' => ['nullable', 'string'],
            'is_admin' => ['boolean'],
            'permissions' => ['required', 'array'],
        ]);
    }
}
