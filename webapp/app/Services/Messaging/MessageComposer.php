<?php

namespace App\Services\Messaging;

use App\Models\Contact;
use App\Models\DataObjectRecord;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Services\Tenancy\MailSettings;
use Illuminate\Support\Facades\DB;

/** Builds queued email/SMS rows from templates + merge fields. Delivery is done by queue jobs. */
class MessageComposer
{
    public function queueEmail(Contact $contact, array $spec, ?DataObjectRecord $record = null): ?EmailMessage
    {
        if ($contact->email_opt_out || ! $contact->email || EmailSuppression::where('email', $contact->email)->exists()) {
            return null;
        }

        $template = isset($spec['template_id']) ? EmailTemplate::find($spec['template_id']) : null;
        $merge = $this->context($contact, $record, $record === null && $this->usesRecordFields(($spec['subject'] ?? $template?->subject ?? '').($spec['html'] ?? $template?->html ?? '')));

        return EmailMessage::create([
            'contact_id' => $contact->id,
            'data_object_record_id' => $record?->id,
            'subject' => $this->merge($spec['subject'] ?? $template?->subject ?? '(no subject)', $merge),
            'html' => $this->merge($spec['html'] ?? $template?->html ?? '', $merge),
            'from_email' => $spec['from_email'] ?? Setting::get('mail.from_email', app(MailSettings::class)->from()['address']),
            'from_name' => $spec['from_name'] ?? Setting::get('mail.from_name', app(MailSettings::class)->from()['name']),
            'to' => [['email' => $contact->email, 'name' => $contact->full_name]],
            'scheduled_at' => isset($spec['delay_minutes']) ? now()->addMinutes((int) $spec['delay_minutes']) : null,
            'attachments' => $spec['attachments'] ?? null,
            'status' => 'queued',
            // automations and bulk e-mail carry the unsubscribe footer; one-to-one e-mail typed by a person does not
            'marketing' => (bool) ($spec['marketing'] ?? $record !== null),
        ]);
    }

    public function queueSms(Contact $contact, array $spec, ?DataObjectRecord $record = null): ?SmsMessage
    {
        if (! $contact->phone) {
            return null;
        }

        return SmsMessage::create([
            'contact_id' => $contact->id,
            'data_object_record_id' => $record?->id,
            'to_number' => $contact->phone,
            'body' => $this->merge($spec['body'] ?? '', $this->context($contact, $record, $record === null && $this->usesRecordFields($spec['body'] ?? '')), escape: false),   // SMS is plain text: no HTML escaping
            'scheduled_at' => isset($spec['delay_minutes']) ? now()->addMinutes((int) $spec['delay_minutes']) : null,
        ]);
    }

    /** Replaces {{contact.first_name}} / {{record.field_key}} placeholders. Unknown keys become empty. */
    public function merge(string $text, array $data, bool $escape = true): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($data, $escape) {
            $value = data_get($data, $m[1]);

            $value = is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : (is_bool($value) ? ($value ? 'Yes' : 'No') : $value);        // a multi-choice field reads "a, b"; a checkbox reads Yes / No

            return is_scalar($value) ? ($escape ? e((string) $value) : (string) $value) : '';
        }, $text);
    }

    /** Does the text use a {{record.field}} placeholder (the contact's connected data)? Only then is the look-up worth making. */
    private function usesRecordFields(string $text): bool
    {
        return (bool) preg_match('/\{\{\s*record\./i', $text);
    }

    /**
     * What {{record.field}} means when a message goes to a contact without one record in hand (an e-mail or text written by a person, Email all, Text all):
     * the contact's connected items, the most recently changed first, and for each field the first value that is filled in. So {{record.patient_name}}
     * is the name on the contact's own patient record. When two data objects have a field of the same name, {{record.<object>.<field>}} says which one:
     * for each object, its most recently changed item connected to the contact.
     *
     * @return array<string, mixed>
     */
    private function connectedData(Contact $contact): array
    {
        $merged = [];
        $byObject = [];
        DataObjectRecord::whereIn('id', DB::table('data_object_record_contact')->where('contact_id', $contact->id)->select('record_id'))
            ->with('dataObject:id,name')->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get(['id', 'data', 'data_object_id'])
            ->each(function ($r) use (&$merged, &$byObject) {
                $slug = $r->dataObject?->mergeSlug();
                $slug && ! isset($byObject[$slug]) && $byObject[$slug] = (array) $r->data;             // the newest item of each object
                foreach ((array) $r->data as $key => $value) {
                    if (! array_key_exists($key, $merged) && $value !== null && $value !== '' && $value !== []) {
                        $merged[$key] = $value;
                    }
                }
            });

        return $merged + $byObject;                                // a field called like an object keeps its own value
    }

    /** @return array<string, mixed> the fields of one item, plain ({{record.field}}) and under the name of its object ({{record.<object>.<field>}}) */
    private function recordData(DataObjectRecord $record): array
    {
        $record->loadMissing('dataObject:id,name');
        $data = (array) $record->data;
        $slug = $record->dataObject?->mergeSlug();

        return $slug ? $data + [$slug => $data] : $data;
    }

    /** Values available to {{merge.fields}}. */
    public function context(Contact $contact, ?DataObjectRecord $record = null, bool $withConnected = false): array
    {
        return [
            'contact' => $contact->only(['first_name', 'last_name', 'email', 'phone', 'company_name', 'job_title']) + ['full_name' => $contact->full_name],
            'record' => $record ? $this->recordData($record) : ($withConnected ? $this->connectedData($contact) : []),
            'app' => ['name' => config('app.name'), 'url' => config('app.url')],
        ];
    }
}
