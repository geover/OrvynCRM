<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataObjectRelationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Relationship types that link contacts to data object records (e.g. "Owner", "Buyer"). */
class RelationshipController extends Controller
{
    public function index()
    {
        return response()->json(['data' => DataObjectRelationship::orderBy('label')->get(['id', 'label', 'description'])]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['label' => ['required', 'string', 'max:150', 'unique:data_object_relationships,label'], 'description' => ['nullable', 'string']]);

        return response()->json(['data' => DataObjectRelationship::create($data)], 201);
    }

    public function destroy(Request $request, DataObjectRelationship $relationship): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $relationship->delete();

        return response()->json(null, 204);
    }
}
