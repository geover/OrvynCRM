<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Support\FullText;
use Illuminate\Http\Request;

/** Universal search: contacts + records across all data objects, capped and fast. */
class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $term = trim($request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        $contacts = Contact::search($term)->limit(8)->get(['id', 'first_name', 'last_name', 'email', 'company_name'])
            ->map(fn ($c) => ['type' => 'contact', 'id' => $c->id, 'title' => $c->full_name, 'subtitle' => $c->email ?: $c->company_name]);

        $objects = DataObject::pluck('name', 'id');
        $records = DataObjectRecord::query()
            ->when(
                FullText::booleanQuery($term),
                fn ($q, $boolean) => $q->whereRaw('MATCH(search_text) AGAINST (? IN BOOLEAN MODE)', [$boolean]),   // index-assisted
                fn ($q) => $q->where('search_text', 'like', '%'.addcslashes($term, '%_\\').'%'),
            )
            ->limit(8)->get(['id', 'data_object_id', 'search_text'])
            ->map(fn ($r) => ['type' => 'record', 'id' => $r->id, 'data_object_id' => $r->data_object_id,
                'title' => str($r->search_text)->limit(60), 'subtitle' => $objects[$r->data_object_id] ?? '']);

        return response()->json(['data' => $contacts->concat($records)->values()]);
    }
}
