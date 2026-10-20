<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\CustomTab;
use App\Models\DataObject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Drives the contact record's sidebar: one tab per data object (with counts) + custom tabs the user may see. */
class ContactDataObjectController extends Controller
{
    public function __invoke(Request $request, Contact $contact): JsonResponse
    {
        $user = $request->user();
        $counts = DB::table('data_object_record_contact as rc')
            ->join('data_object_records as r', 'r.id', '=', 'rc.record_id')
            ->where('rc.contact_id', $contact->id)->whereNull('r.deleted_at')
            ->groupBy('r.data_object_id')->selectRaw('r.data_object_id, count(distinct r.id) as total')->pluck('total', 'data_object_id');

        $objects = DataObject::where('status', 'active')->orderBy('name')->get(['id', 'name', 'icon'])
            ->filter(fn ($o) => $user->hasPermission("data_objects.{$o->id}.view") || $user->hasPermission('data_objects.view'))
            ->map(fn ($o) => $o->toArray() + ['count' => (int) ($counts[$o->id] ?? 0)])->values();

        $tabs = CustomTab::where('placement', 'contact')->orderBy('position')->get()
            ->filter(fn ($t) => $t->isFor($user))
            ->map(fn ($t) => $t->only(['id', 'title', 'type', 'icon']))->values();

        return response()->json(['objects' => $objects, 'custom_tabs' => $tabs]);
    }
}
