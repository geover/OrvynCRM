<?php

namespace App\Http\Resources;

use App\Models\DataObjectRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DataObjectRecord */
class RecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'data_object_id' => $this->data_object_id,
            'data' => $this->data,
            'status' => $this->status,
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->full_name, 'relationship_id' => $c->pivot->relationship_id,
                'email' => $c->getAttributes()['email'] ?? null, 'phone' => $c->getAttributes()['phone'] ?? null,          // only when the query selected them
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
