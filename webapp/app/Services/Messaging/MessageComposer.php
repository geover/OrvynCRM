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

/** Builds queued email/SMS rows from templates + merge fields. Delivery is done by queue jobs. */
class MessageComposer
{
    public function queueEmail(Contact $contact, array $spec, ?DataObjectRecord $record = null): ?EmailMessage
    {
        if ($contact->email_opt_out || ! $contact->email || EmailSuppression::where('email', $contact->email)->exists()) {
            return null;
        }

        $template = isset($spec['template_id']) ? EmailTemplate::find($spec['template_id']) : null;
        $merge = $this->context($contact, $record);

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
            'body' => $this->merge($spec['body'] ?? '', $this->context($contact, $record), escape: false),   // SMS is plain text: no HTML escaping
            'scheduled_at' => isset($spec['delay_minutes']) ? now()->addMinutes((int) $spec['delay_minutes']) : null,
        ]);
    }

    /** Replaces {{contact.first_name}} / {{record.field_key}} placeholders. Unknown keys become empty. */
    public function merge(string $text, array $data, bool $escape = true): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($data, $escape) {
            $value = data_get($data, $m[1]);

            return is_scalar($value) ? ($escape ? e((string) $value) : (string) $value) : '';
        }, $text);
    }

    /** Values available to {{merge.fields}}. */
    public function context(Contact $contact, ?DataObjectRecord $record = null): array
    {
        return [
            'contact' => $contact->only(['first_name', 'last_name', 'email', 'phone', 'company_name', 'job_title']) + ['full_name' => $contact->full_name],
            'record' => $record?->data ?? [],
            'app' => ['name' => config('app.name'), 'url' => config('app.url')],
        ];
    }
}
