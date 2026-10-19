<?php

namespace App\Services\Messaging;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\EmailMessage;

/**
 * Letting people leave a mailing list by themselves: the link that goes in every automation and bulk e-mail, the footer it sits in, and what
 * an unsubscribe does. The link is the message's own unguessable tracking id, so it needs no sign-in and names the company that sent it.
 */
class Unsubscribes
{
    /** The merge field a template can use to place the link itself. */
    public const PLACEHOLDER = '{{unsubscribe_url}}';

    public function url(EmailMessage $message): string
    {
        return url('/u/'.$message->tracker_id);
    }

    /**
     * The message's HTML with the link in it: wherever the template put {{unsubscribe_url}}, or, for automation and bulk e-mail that did not,
     * a short footer. One-to-one e-mails typed by a person are left alone.
     */
    public function withLink(EmailMessage $message, string $companyName): string
    {
        $url = $this->url($message);
        $html = (string) $message->html;
        if (str_contains($html, self::PLACEHOLDER)) {
            return str_replace(self::PLACEHOLDER, $url, $html);
        }
        if (! $message->marketing) {
            return $html;
        }
        $company = e($companyName);

        return $html.'<div style="margin-top:28px;padding-top:14px;border-top:1px solid #e5e7eb;font:12px/1.5 Arial,sans-serif;color:#6b7280">'
            ."You are receiving this e-mail because you are in {$company}'s contacts. "
            .'<a href="'.e($url).'" style="color:#6b7280;text-decoration:underline">Unsubscribe</a></div>';
    }

    /** Opts the contact out of e-mail and says so on their record. Safe to repeat. */
    public function optOut(Contact $contact, ?EmailMessage $message = null): void
    {
        if ($contact->email_opt_out) {
            return;
        }
        $contact->forceFill(['email_opt_out' => true, 'email_opt_out_at' => now()])->save();
        Activity::create([
            'contact_id' => $contact->id, 'type' => 'email.unsubscribed', 'subject_type' => $message ? EmailMessage::class : null, 'subject_id' => $message?->id,
            'data' => ['via' => 'unsubscribe link'],
        ]);
    }

    /** A contact who changed their mind (or pressed the link by mistake). */
    public function optIn(Contact $contact, ?EmailMessage $message = null): void
    {
        if (! $contact->email_opt_out) {
            return;
        }
        $contact->forceFill(['email_opt_out' => false, 'email_opt_out_at' => null])->save();
        Activity::create([
            'contact_id' => $contact->id, 'type' => 'email.resubscribed', 'subject_type' => $message ? EmailMessage::class : null, 'subject_id' => $message?->id,
            'data' => ['via' => 'unsubscribe page'],
        ]);
    }
}
