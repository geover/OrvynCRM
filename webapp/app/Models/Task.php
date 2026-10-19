<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // the files on a task go with it; the note logged when it was completed stays (it is the record), and just loses the link
        static::deleting(function (Task $task) {
            $task->attachments()->get()->each->delete();
            Note::where('task_id', $task->id)->update(['task_id' => null]);
        });
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ContactAttachment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('completed_at');
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->open()->where('due_at', '<', now());
    }
}
