<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** An image attached to a thread message. The file lives on the platform disk, never in a company's storage. */
class ThreadAttachment extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    /** What may be attached (no SVG: it can carry scripts) and the type each is served as. */
    public const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public const MAX_PER_MESSAGE = 4;

    public const MAX_KILOBYTES = 5120;

    protected $guarded = ['id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ThreadMessage::class, 'message_id');
    }

    public function deleteFile(): void
    {
        Storage::disk('platform')->delete($this->path);
    }
}
