<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One "Email all" or "Text all": the messages it made carry its id (blast_id), so its progress can be added up. */
class Blast extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['finished_queuing_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The messages of this blast: e-mails or texts. */
    public function messages(): Builder
    {
        return ($this->channel === 'email' ? EmailMessage::query() : SmsMessage::query())->where('blast_id', $this->id);
    }
}
