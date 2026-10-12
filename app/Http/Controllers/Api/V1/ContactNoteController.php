<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactNoteController extends Controller
{
    public function index(Contact $contact)
    {
        return $contact->notes()->with('author:id,name')->latest()->latest('id')->cursorPaginate(25);
    }

    public function store(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'], 'body' => ['required', 'string'],
            'tags' => ['nullable', 'array'], 'tags.*' => ['string', 'max:60'],
        ]);

        $note = $contact->notes()->create($data + ['user_id' => $request->user()->id]);
        Activity::create([
            'contact_id' => $contact->id, 'causer_id' => $request->user()->id, 'type' => 'note.created',
            'subject_type' => Note::class, 'subject_id' => $note->id, 'data' => ['title' => $note->title],
        ]);

        return response()->json(['data' => $note->load('author:id,name')], 201);
    }

    public function update(Request $request, Contact $contact, Note $note): JsonResponse
    {
        abort_unless($note->contact_id === $contact->id, 404);
        abort_unless($note->user_id === $request->user()->id || $request->user()->isAdmin(), 403);   // authors (or admins) edit notes
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'], 'body' => ['sometimes', 'string'],
            'tags' => ['nullable', 'array'], 'tags.*' => ['string', 'max:60'],
        ]);
        $note->update($data);

        return response()->json(['data' => $note->load('author:id,name')]);
    }

    /** Distinct tags already used on notes (autocomplete). */
    public function tags(): JsonResponse
    {
        return response()->json(['data' => Note::whereNotNull('tags')->latest('id')->limit(500)->pluck('tags')->flatten()->unique()->sort()->values()]);
    }

    public function destroy(Contact $contact, Note $note): JsonResponse
    {
        abort_unless($note->contact_id === $contact->id, 404);
        $note->delete();

        return response()->json(null, 204);
    }
}
