<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactRelationshipType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin: "Contact to Contact Relationships" (direct + indirect types). */
class ContactRelationshipTypeController extends Controller
{
    public function index()
    {
        return response()->json(['data' => ContactRelationshipType::orderBy('kind')->orderBy('label')->get()]);
    }

    public function sync(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'items' => ['present', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.label' => ['required', 'string', 'max:150', 'distinct:ignore_case'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.kind' => ['required', Rule::in(['direct', 'indirect'])],
        ]);

        $keep = [];
        foreach ($d['items'] as $i) {
            $row = ContactRelationshipType::updateOrCreate(['id' => $i['id'] ?? null], ['label' => $i['label'], 'description' => $i['description'] ?? null, 'kind' => $i['kind']]);
            $keep[] = $row->id;
        }
        ContactRelationshipType::whereNotIn('id', $keep)->delete();

        return $this->index();
    }
}
