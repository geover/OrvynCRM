<?php

namespace App\Actions\Contacts;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveContact
{
    public function handle(Contact $contact, array $data, ?User $by = null): Contact
    {
        return DB::transaction(function () use ($contact, $data, $by) {
            $tags = $data['tags'] ?? null;
            unset($data['tags']);

            $contact->fill($data);
            $isNew = ! $contact->exists;
            $dirty = array_keys($contact->getDirty());
            $contact->save();

            if ($tags !== null) {
                $ids = collect($tags)->map(fn ($name) => Tag::firstOrCreate(['name' => trim($name)])->id);
                $contact->tags()->sync($ids);
            }

            Activity::create([
                'contact_id' => $contact->id, 'causer_id' => $by?->id,
                'type' => $isNew ? 'contact.created' : 'contact.updated',
                'subject_type' => Contact::class, 'subject_id' => $contact->id,
                'data' => $isNew ? null : ['changed' => $dirty],
            ]);

            return $contact->load('owner:id,name', 'tags:id,name');
        });
    }
}
