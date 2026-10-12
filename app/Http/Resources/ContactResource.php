<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'title' => $this->title,
            'website' => $this->website,
            'emails' => $this->emails ?? [],
            'phones' => $this->phones ?? [],
            'email_opt_out' => (bool) $this->email_opt_out,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'email_secondary' => $this->email_secondary,
            'phone' => $this->phone,
            'phone_secondary' => $this->phone_secondary,
            'company_name' => $this->company_name,
            'job_title' => $this->job_title,
            'contact_type' => $this->contact_type,
            'birthday' => $this->birthday?->toDateString(),
            'timezone' => $this->timezone,
            'addresses' => $this->addresses ?? [],
            'custom_fields' => $this->custom_fields ?? [],
            'notes' => $this->notes,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
