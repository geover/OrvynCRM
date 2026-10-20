<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
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

    /**
     * The photo of a platform administrator who has opened this company: their support profile has none of its own, so the one they set on the platform
     * (the account's) is served through the company's file route, which keeps them signed in to the company (the platform's own address would leave it).
     */
    public function supportUrl(User $user, string $base): ?string
    {
        $identity = $user->central_user_id ? AccountUser::find($user->central_user_id) : null;

        return $identity?->avatar_path ? "{$base}/{$user->id}/avatar?v=".($identity->avatar_updated_at?->timestamp ?? 1) : null;
    }

    public function supportResponse(User $user): Response
    {
        $identity = AccountUser::find($user->central_user_id);
        abort_unless($identity?->avatar_path, 404);
        $path = $identity->avatar_path;

        return tenancy()->central(function () use ($path) {                       // the account's file is in the platform's storage, not the company's
            abort_unless(Storage::disk('local')->exists($path), 404);

            return response(Storage::disk('local')->get($path), 200, ['Content-Type' => SquareImage::mimeOf($path), 'Cache-Control' => 'private, max-age=604800, immutable', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    public function save(User $user, UploadedFile $file): void
    {
        $picture = SquareImage::small((string) file_get_contents($file->getRealPath()));
        $path = "avatars/users/{$user->id}.{$picture['extension']}";
        $user->avatar_path && $user->avatar_path !== $path && Storage::disk('local')->delete($user->avatar_path);          // it was a JPEG and is a PNG now, or the other way round
        Storage::disk('local')->put($path, $picture['bytes']);
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

        return response(Storage::disk('local')->get($user->avatar_path), 200, ['Content-Type' => SquareImage::mimeOf($user->avatar_path), 'Cache-Control' => 'private, max-age=604800, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
