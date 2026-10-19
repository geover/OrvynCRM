<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ContactAttachment extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (ContactAttachment $a) {
            $a->path && Storage::disk($a->disk ?? 'local')->delete($a->path);        // the file goes with its row, however the row is removed
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
