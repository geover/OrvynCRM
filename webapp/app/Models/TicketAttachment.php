<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Symfony\Component\HttpFoundation\Response;

/** A screenshot or document attached to a ticket line. The file lives on the platform disk, never in a company's storage. */
class TicketAttachment extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    /** What may be attached (no SVG or HTML: they can carry scripts) and the type each is served as. */
    public const TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'log' => 'text/plain', 'csv' => 'text/csv',
    ];

    public const MAX_PER_REPLY = 5;

    public const MAX_KILOBYTES = 8192;

    protected $guarded = ['id'];

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'reply_id');
    }

    /** The file as the browser should get it: a picture inline, anything else as a download; its own type, no sniffing, private, and a policy that lets nothing run. */
    public function response(): Response
    {
        abort_unless(Storage::disk('platform')->exists($this->path), 404);
        $name = str_replace(['"', "\r", "\n"], '', $this->original_name);

        return response(Storage::disk('platform')->get($this->path), 200, [
            'Content-Type' => $this->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => (str_starts_with($this->mime, 'image/') ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function deleteFile(): void
    {
        Storage::disk('platform')->delete($this->path);
    }
}
