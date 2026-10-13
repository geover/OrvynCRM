<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\DataObject;
use App\Models\DataObjectAttachment;
use App\Models\DataObjectRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Per-item history and file attachments (the old data object item "History" and "Attachments" panels). */
class DataObjectItemExtrasController extends Controller
{
    public function history(Request $request, DataObject $dataObject, DataObjectRecord $record): JsonResponse
    {
        $this->guard($request, $dataObject, $record, 'view');
        $labels = $dataObject->fields()->pluck('label', 'key');

        // The same event is stored once per linked contact; collapse the duplicates for display.
        $rows = Activity::with('causer:id,name')->where('subject_type', DataObjectRecord::class)->where('subject_id', $record->id)
            ->orderByDesc('id')->limit(300)->get()->unique(fn ($a) => $a->type.'|'.$a->created_at->timestamp.'|'.md5(json_encode($a->data['diff'] ?? $a->data['contact_id'] ?? '')))->take(100);

        return response()->json(['data' => $rows->map(fn ($a) => [
            'id' => $a->id, 'type' => $a->type, 'by' => $a->causer?->name, 'at' => $a->created_at,
            'changes' => collect($a->data['diff'] ?? [])->map(fn ($v, $k) => ['field' => $labels[$k] ?? $k, 'from' => $v[0], 'to' => $v[1]])->values(),
        ])->values()]);
    }

    public function attachments(Request $request, DataObject $dataObject, DataObjectRecord $record): JsonResponse
    {
        $this->guard($request, $dataObject, $record, 'view');

        return response()->json(['data' => DataObjectAttachment::where('record_id', $record->id)->latest('id')->get(['id', 'filename', 'mime', 'size', 'created_at'])]);
    }

    public function upload(Request $request, DataObject $dataObject, DataObjectRecord $record): JsonResponse
    {
        $this->guard($request, $dataObject, $record, 'edit');
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,gif,webp,doc,docx,xls,xlsx,csv,txt,zip,ppt,pptx']]);
        $f = $request->file('file');
        $a = DataObjectAttachment::create([
            'record_id' => $record->id, 'disk' => 'local', 'path' => $f->store("records/{$record->id}", 'local'),
            'filename' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'size' => $f->getSize(),
        ]);

        return response()->json(['data' => $a->only(['id', 'filename', 'mime', 'size'])], 201);
    }

    public function download(Request $request, DataObject $dataObject, DataObjectRecord $record, DataObjectAttachment $attachment): StreamedResponse
    {
        $this->guard($request, $dataObject, $record, 'view');
        abort_unless($attachment->record_id === $record->id, 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename);
    }

    public function destroyAttachment(Request $request, DataObject $dataObject, DataObjectRecord $record, DataObjectAttachment $attachment): JsonResponse
    {
        $this->guard($request, $dataObject, $record, 'edit');
        abort_unless($attachment->record_id === $record->id, 404);
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    private function guard(Request $request, DataObject $object, DataObjectRecord $record, string $ability): void
    {
        abort_unless($record->data_object_id === $object->id, 404);
        $u = $request->user();
        abort_unless($u->hasPermission("data_objects.{$object->id}.$ability") || $u->hasPermission("data_objects.$ability"), 403);
    }
}
