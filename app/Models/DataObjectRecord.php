<?php

namespace App\Models;

use App\Services\DataObjects\RecordIndexer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataObjectRecord extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    protected static function booted(): void
    {
        // Keep the typed index table and the FULLTEXT column in sync with the JSON document.
        static::saved(fn (self $r) => app(RecordIndexer::class)->index($r));
    }

    public function dataObject(): BelongsTo
    {
        return $this->belongsTo(DataObject::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'data_object_record_contact', 'record_id', 'contact_id')
            ->withPivot(['relationship_id', 'contact_values'])->withTimestamps();
    }
}
