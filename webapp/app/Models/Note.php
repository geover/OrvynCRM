<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Note extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    protected static function booted(): void
    {
        // the files on a note go with it (a task made from the note stays, and just loses the link)
        static::deleting(function (Note $note) {
            $note->attachments()->get()->each->delete();
            Task::where('note_id', $note->id)->update(['note_id' => null]);
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ContactAttachment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
