<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactAttachmentController extends Controller
{
    public function index(Contact $contact): JsonResponse
    {
        return response()->json(['data' => $contact->attachments()->with('uploader:id,name')->latest()->get()->map(fn ($a) => [
            'id' => $a->id, 'title' => $a->title, 'mime' => $a->mime, 'size' => $a->size, 'url' => $a->url,
            'is_link' => (bool) $a->url, 'uploaded_by' => $a->uploader?->name, 'created_at' => $a->created_at,
        ])]);
    }

    /** Upload a file OR attach a link. */
    public function store(Request $request, Contact $contact): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.edit'), 403);
        $d = $request->validate([
            'file' => ['required_without:url', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,gif,webp,doc,docx,xls,xlsx,csv,txt,zip,ppt,pptx'],
            'url' => ['required_without:file', 'nullable', 'url:http,https', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        if ($file = $request->file('file')) {
            $path = $file->store("contacts/{$contact->id}", 'local');
            $a = $contact->attachments()->create([
                'title' => $d['title'] ?? $file->getClientOriginalName(), 'disk' => 'local', 'path' => $path,
                'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $request->user()->id,
            ]);
        } else {
            $a = $contact->attachments()->create(['title' => $d['title'] ?? $d['url'], 'url' => $d['url'], 'uploaded_by' => $request->user()->id]);
        }

        return response()->json(['data' => $a->only(['id', 'title', 'mime', 'size', 'url'])], 201);
    }

    public function download(Contact $contact, ContactAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->contact_id === $contact->id && $attachment->path, 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->title);
    }

    public function destroy(Request $request, Contact $contact, ContactAttachment $attachment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.edit') && $attachment->contact_id === $contact->id, 403);
        $attachment->path && Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }
}
