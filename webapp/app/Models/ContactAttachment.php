<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactAttachment extends Model
{
    protected $guarded = ['id'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
