<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\DataObjectRelationship;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Relationship types that link contacts to data object records (e.g. "Owner", "Buyer"). */
class RelationshipController extends Controller
{
    /** Each type with how much it is used, so the editor can say what deleting it would remove. */
    public function index()
    {
        $links = DB::table('data_object_record_contact')->selectRaw('relationship_id, count(*) as n')->groupBy('relationship_id')->pluck('n', 'relationship_id');
        $objects = DB::table('data_object_relationship_rules')->selectRaw('relationship_id, count(*) as n')->groupBy('relationship_id')->pluck('n', 'relationship_id');

        return response()->json(['data' => DataObjectRelationship::orderBy('label')->get(['id', 'label', 'description'])
            ->map(fn ($r) => $r->toArray() + ['links' => (int) ($links[$r->id] ?? 0), 'objects' => (int) ($objects[$r->id] ?? 0)])]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['label' => ['required', 'string', 'max:150', 'unique:data_object_relationships,label'], 'description' => ['nullable', 'string']]);

        return response()->json(['data' => DataObjectRelationship::create($data)], 201);
    }

    /**
     * Deleting a type clears it everywhere: every contact that was connected to an item through it is disconnected (the contact and the item stay),
     * data objects stop listing it, and automations and forms that named it no longer do.
     */
    public function destroy(Request $request, DataObjectRelationship $relationship): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        DB::transaction(function () use ($relationship) {
            $links = DB::table('data_object_record_contact')->where('relationship_id', $relationship->id);
            $contactIds = (clone $links)->distinct()->pluck('contact_id');
            $links->delete();
            DB::table('data_object_relationship_rules')->where('relationship_id', $relationship->id)->delete();
            $contactIds->isNotEmpty() && Contact::whereKey($contactIds)->update(['updated_at' => now()]);

            foreach (Automation::all() as $automation) {
                $actions = collect($automation->actions ?? [])->map(fn ($a) => (int) ($a['relationship_id'] ?? 0) === $relationship->id ? array_merge($a, ['relationship_id' => null]) : $a)->all();
                $actions !== ($automation->actions ?? []) && $automation->update(['actions' => $actions]);
            }
            foreach (Form::all() as $form) {
                if ((int) ($form->settings['relationship_id'] ?? 0) === $relationship->id) {
                    $form->update(['settings' => array_merge($form->settings, ['relationship_id' => null])]);
                }
            }
            $relationship->delete();
        });

        return response()->json(null, 204);
    }
}
