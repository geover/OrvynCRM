<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DataObject extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $o) {
            $o->slug ??= Str::slug($o->name);
        });
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DataObjectField::class)->orderBy('position')->orderBy('id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(DataObjectRecord::class);
    }

    /** The name of this data object as it is written inside a merge field: {{record.<this>.<field>}}. "Veterinary Patients" is veterinary_patients. */
    public function mergeSlug(): string
    {
        return Str::slug($this->name, '_');
    }

    public function relationships(): BelongsToMany
    {
        return $this->belongsToMany(DataObjectRelationship::class, 'data_object_relationship_rules', 'data_object_id', 'relationship_id')
            ->withPivot(['is_exclusive', 'max_contacts', 'auto_assign_user', 'auto_assign_contact']);
    }
}
