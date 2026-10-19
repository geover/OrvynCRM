<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
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

        $bytes = $this->square((string) file_get_contents($request->file('file')->getRealPath()));
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

    /** The middle of the picture as a SIZE x SIZE JPEG. */
    private function square(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            abort_if(strlen($bytes) > 400 * 1024, 422, 'This server cannot resize pictures; use one under 400 KB.');

            return $bytes;
        }
        $src = @imagecreatefromstring($bytes);
        abort_unless($src, 422, 'That is not a picture we can read.');
        [$w, $h] = [imagesx($src), imagesy($src)];
        abort_if($w * $h > 40_000_000, 422, 'That picture is too large.');
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));                    // a transparent picture sits on white
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($dst, null, 82);

        return (string) ob_get_clean();
    }
}
