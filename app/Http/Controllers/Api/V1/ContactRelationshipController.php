<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactRelationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Direct = explicit contact<->contact links (e.g. Spouse). Indirect = contacts that share a data-object record
 * with this contact (computed, read-only), like the old "Direct / Indirect Relationships" panels.
 */
class ContactRelationshipController extends Controller
{
    public function index(Contact $contact): JsonResponse
    {
        $direct = ContactRelationship::with(['type:id,label', 'contact:id,first_name,last_name,email', 'related:id,first_name,last_name,email'])
            ->where(fn ($q) => $q->where('contact_id', $contact->id)->orWhere('related_contact_id', $contact->id))
            ->get()
            ->map(function ($r) use ($contact) {
                $other = $r->contact_id === $contact->id ? $r->related : $r->contact;

                return ['id' => $r->id, 'type' => $r->type->label, 'type_id' => $r->type_id, 'direction' => $r->contact_id === $contact->id ? 'out' : 'in',
                    'contact' => ['id' => $other->id, 'name' => $other->full_name, 'email' => $other->email]];
            });

        // contacts sharing at least one record, grouped by the record's object
        $indirect = DB::table('data_object_record_contact as me')
            ->join('data_object_record_contact as other', fn ($j) => $j->on('other.record_id', '=', 'me.record_id')->whereColumn('other.contact_id', '!=', 'me.contact_id'))
            ->join('contacts', 'contacts.id', '=', 'other.contact_id')
            ->join('data_object_records as r', 'r.id', '=', 'me.record_id')
            ->join('data_objects as o', 'o.id', '=', 'r.data_object_id')
            ->join('data_object_relationships as rel', 'rel.id', '=', 'other.relationship_id')
            ->where('me.contact_id', $contact->id)->whereNull('contacts.deleted_at')->whereNull('r.deleted_at')
            ->select('contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.email', 'o.name as object', 'rel.label as relationship', 'r.id as record_id', 'o.id as object_id')
            ->limit(200)->get();

        return response()->json(['direct' => $direct, 'indirect' => $indirect->map(fn ($i) => [
            'id' => $i->id, 'name' => trim("$i->first_name $i->last_name") ?: $i->email, 'email' => $i->email,
            'object' => $i->object, 'object_id' => $i->object_id, 'record_id' => $i->record_id, 'relationship' => $i->relationship,
        ])]);
    }

    public function store(Request $request, Contact $contact): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.edit'), 403);
        $d = $request->validate([
            'related_contact_id' => ['required', 'integer', 'exists:contacts,id', Rule::notIn([$contact->id])],
            'type_id' => ['required', 'exists:contact_relationship_types,id'],
        ]);

        $rel = ContactRelationship::firstOrCreate(['contact_id' => $contact->id, 'related_contact_id' => $d['related_contact_id'], 'type_id' => $d['type_id']]);

        return response()->json(['data' => $rel], 201);
    }

    public function destroy(Request $request, Contact $contact, ContactRelationship $relationship): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.edit'), 403);
        abort_unless(in_array($contact->id, [$relationship->contact_id, $relationship->related_contact_id], true), 404);
        $relationship->delete();

        return response()->json(null, 204);
    }
}
