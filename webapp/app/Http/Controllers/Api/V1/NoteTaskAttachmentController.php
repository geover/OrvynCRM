<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactAttachment;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Files and links on a note or a task. They are also the contact's attachments, so the contact's Attachments tab lists them too. */
class NoteTaskAttachmentController extends Controller
{
    public function forNote(Request $request, Note $note): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.edit'), 403);

        return $this->attach($request, $note->contact_id, ['note_id' => $note->id], "contacts/{$note->contact_id}/notes");
    }

    public function forTask(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->hasPermission('contacts.edit') || in_array($user->id, [$task->created_by, $task->assigned_to], true), 403);
        abort_unless($task->contact_id, 422, 'Attach files to a task that belongs to a contact.');

        return $this->attach($request, $task->contact_id, ['task_id' => $task->id], "contacts/{$task->contact_id}/tasks");
    }

    public function download(ContactAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->path, 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->title);
    }

    public function destroy(Request $request, ContactAttachment $attachment): JsonResponse
    {
        $user = $request->user();
        abort_unless($attachment->note_id || $attachment->task_id, 404);
        abort_unless($user->isAdmin() || $user->hasPermission('contacts.edit') || $attachment->uploaded_by === $user->id, 403);
        $attachment->path && Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    /** @param  array<string, int>  $owner  note_id or task_id */
    private function attach(Request $request, int $contactId, array $owner, string $folder): JsonResponse
    {
        $d = $request->validate([
            'file' => ['required_without:url', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,gif,webp,doc,docx,xls,xlsx,csv,txt,zip,ppt,pptx'],
            'url' => ['required_without:file', 'nullable', 'url:http,https', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);
        $by = ['contact_id' => $contactId, 'uploaded_by' => $request->user()->id] + $owner;

        if ($file = $request->file('file')) {
            $a = ContactAttachment::create($by + [
                'title' => $d['title'] ?? $file->getClientOriginalName(), 'disk' => 'local', 'path' => $file->store($folder, 'local'),
                'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            ]);
        } else {
            $a = ContactAttachment::create($by + ['title' => $d['title'] ?? $d['url'], 'url' => $d['url']]);
        }

        return response()->json(['data' => $a->only(['id', 'note_id', 'task_id', 'title', 'mime', 'size', 'url', 'created_at'])], 201);
    }
}
