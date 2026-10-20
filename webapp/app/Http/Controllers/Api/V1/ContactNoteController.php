<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Note;
use App\Support\SafeHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContactNoteController extends Controller
{
    public function index(Contact $contact)
    {
        return $contact->notes()->with('author:id,name', 'task:id,title,completed_at', 'attachments:id,note_id,task_id,title,mime,size,url,created_at')->latest()->latest('id')->cursorPaginate(25);
    }

    public function store(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'], 'body' => ['required', 'string'],
            'tags' => ['nullable', 'array'], 'tags.*' => ['string', 'max:60'],
        ]);

        $data['body'] = $this->cleanBody($data['body']);

        $note = $contact->notes()->create($data + ['user_id' => $request->user()->id]);
        Activity::create([
            'contact_id' => $contact->id, 'causer_id' => $request->user()->id, 'type' => 'note.created',
            'subject_type' => Note::class, 'subject_id' => $note->id, 'data' => ['title' => $note->title],
        ]);

        return response()->json(['data' => $note->load('author:id,name', 'task:id,title,completed_at', 'attachments:id,note_id,task_id,title,mime,size,url,created_at')], 201);
    }

    public function update(Request $request, Contact $contact, Note $note): JsonResponse
    {
        abort_unless($note->contact_id === $contact->id, 404);
        abort_unless($note->user_id === $request->user()->id || $request->user()->isAdmin(), 403);   // authors (or admins) edit notes
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'], 'body' => ['sometimes', 'string'],
            'tags' => ['nullable', 'array'], 'tags.*' => ['string', 'max:60'],
        ]);
        if (array_key_exists('body', $data)) {
            $data['body'] = $this->cleanBody($data['body']);
        }
        $note->update($data);

        return response()->json(['data' => $note->load('author:id,name', 'task:id,title,completed_at', 'attachments:id,note_id,task_id,title,mime,size,url,created_at')]);
    }

    /** The note's text is formatted text from the rich text editor, kept safe; a note with nothing written in it (an empty editor) is refused. */
    private function cleanBody(string $body): string
    {
        $clean = SafeHtml::clean($body);
        if ($clean === null || trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5)) === '') {
            throw ValidationException::withMessages(['body' => 'Write something in the note.']);
        }

        return $clean;
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
