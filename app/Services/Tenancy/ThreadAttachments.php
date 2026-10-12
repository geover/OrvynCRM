<?php

namespace App\Services\Tenancy;

use App\Models\ThreadAttachment;
use App\Models\ThreadMessage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Storing, validating and serving the images attached to conversation messages. */
class ThreadAttachments
{
    /**
     * Validation for a message that may carry images: the text can be empty when there is at least one image.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachments'],
            'attachments' => ['sometimes', 'array', 'max:'.ThreadAttachment::MAX_PER_MESSAGE],
            'attachments.*' => ['file', 'image', 'mimes:'.implode(',', array_keys(ThreadAttachment::TYPES)), 'max:'.ThreadAttachment::MAX_KILOBYTES],
        ];
    }

    /** @param  list<UploadedFile>  $files */
    public function store(ThreadMessage $message, array $files): void
    {
        foreach ($files as $file) {
            $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $mime = ThreadAttachment::TYPES[$ext] ?? null;
            if (! $mime) {
                continue;                                             // validation already allows only these; never store anything else
            }
            $message->attachments()->create([
                'path' => $file->storeAs('threads', bin2hex(random_bytes(16)).'.'.$ext, 'platform'),
                'original_name' => str($file->getClientOriginalName())->limit(120, '')->toString(),
                'mime' => $mime, 'size' => $file->getSize(),
            ]);
        }
    }

    /** The image as the browser should get it: inline, its own type, no sniffing, private. */
    public function response(ThreadAttachment $attachment): Response
    {
        abort_unless(Storage::disk('platform')->exists($attachment->path), 404);

        return ResponseFactory::make(Storage::disk('platform')->get($attachment->path), 200, [
            'Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline; filename="'.str_replace(['"', "\r", "\n"], '', $attachment->original_name).'"',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /** @return list<UploadedFile> */
    public function files(Request $request): array
    {
        return array_values(array_filter((array) $request->file('attachments'), fn ($f) => $f instanceof UploadedFile));
    }

    public function description(int $count): string
    {
        return $count === 0 ? '' : ($count === 1 ? '1 image attached' : "{$count} images attached");
    }
}
