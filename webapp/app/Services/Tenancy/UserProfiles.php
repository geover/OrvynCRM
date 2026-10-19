<?php

namespace App\Services\Tenancy;

use App\Models\User;
use App\Support\SquareImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The basic details of a person in a company, and their photo (cut to a small square once, kept by the browser until it changes). Used inside the company's database. */
class UserProfiles
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone' => ['sometimes', 'timezone:all'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** The address of the photo for the page, or null. `$base` is where it is served from (the company's file route, or the platform's). */
    public function url(User $user, string $base): ?string
    {
        return $user->avatar_path ? "{$base}/{$user->id}/avatar?v=".($user->avatar_updated_at?->timestamp ?? 1) : null;
    }

    public function save(User $user, UploadedFile $file): void
    {
        $path = "avatars/users/{$user->id}.jpg";
        Storage::disk('local')->put($path, SquareImage::jpeg((string) file_get_contents($file->getRealPath())));
        $user->forceFill(['avatar_path' => $path, 'avatar_updated_at' => now()])->save();
    }

    public function remove(User $user): void
    {
        $user->avatar_path && Storage::disk('local')->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => null, 'avatar_updated_at' => null])->save();
    }

    public function response(User $user): Response
    {
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return response(Storage::disk('local')->get($user->avatar_path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
