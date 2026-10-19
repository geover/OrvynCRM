<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\SquareImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A contact's own photo. It is cut to a small square once, when it is uploaded, so showing it in a list costs a few kilobytes, and it is served with a
 * long cache lifetime (the address carries a version that changes when the photo does).
 */
class ContactAvatarController extends Controller
{
    private const SIZE = 256;

    public function store(Request $request, Contact $contact): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.edit'), 403);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp,gif']]);

        $bytes = SquareImage::jpeg((string) file_get_contents($request->file('file')->getRealPath()), self::SIZE);
        $path = "avatars/{$contact->id}.jpg";
        $old = $contact->avatar_path;
        Storage::disk('local')->put($path, $bytes);
        $old && $old !== $path && Storage::disk('local')->delete($old);
        $contact->forceFill(['avatar_path' => $path, 'avatar_updated_at' => now()])->save();

        return response()->json(['data' => $contact->avatar()]);
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.edit'), 403);
        $contact->avatar_path && Storage::disk('local')->delete($contact->avatar_path);
        $contact->forceFill(['avatar_path' => null, 'avatar_updated_at' => null])->save();

        return response()->json(['data' => $contact->avatar()]);
    }

    public function show(Contact $contact): Response
    {
        abort_unless($contact->avatar_path && Storage::disk('local')->exists($contact->avatar_path), 404);

        return response(Storage::disk('local')->get($contact->avatar_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800, immutable',        // the address changes with the photo
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
