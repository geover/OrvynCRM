<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Temporary uploads (email attachments, import files). Files live under storage/app/tmp until used or pruned. */
class UploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,gif,webp,doc,docx,xls,xlsx,csv,txt,zip,ppt,pptx']]);
        $file = $request->file('file');
        $path = $file->store('tmp/'.$request->user()->id, 'local');

        return response()->json(['path' => $path, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize()], 201);
    }
}
