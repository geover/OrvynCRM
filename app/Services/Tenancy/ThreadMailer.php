<?php

namespace App\Services\Tenancy;

use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mails the other side when a message arrives in a thread. The inbox and the badges are the real notification; mail
 * is a convenience, so a mail problem never blocks a message.
 */
class ThreadMailer
{
    /** A company administrator wrote: tell every platform administrator. */
    public function toPlatform(MessageThread $thread, ThreadMessage $message, bool $isNew): void
    {
        $company = Tenant::find($thread->tenant_id);
        $text = "{$message->sender_name} ({$message->sender_email}), company administrator of {$company?->name}, ".($isNew ? 'started a conversation' : 'replied').":\n\n"
            ."{$thread->subject}\n\n".$this->content($message)."\n\nOpen the thread under Platform > Messages: ".url("/platform/messages?thread={$thread->id}");

        app(PlatformNotifier::class)->mail(PlatformNotifier::MESSAGES, "[{$company?->name}] {$thread->subject}", $text, [$message->sender_email, $message->sender_name]);
    }

    /** A platform administrator replied: tell the administrators of that company. */
    public function toCompany(MessageThread $thread, ThreadMessage $message): void
    {
        $tenant = Tenant::find($thread->tenant_id);
        if (! $tenant) {
            return;
        }
        $emails = $tenant->run(fn () => User::listed()->with('permissionTemplate')->where('status', 'active')->get()->filter(fn (User $u) => $u->isAdmin())->pluck('email')->all());
        $text = "{$message->sender_name} from the platform replied:\n\n{$thread->subject}\n\n".$this->content($message)."\n\nOpen the conversation under Admin settings > Message the platform: ".url("/admin/platform-messages?thread={$thread->id}");

        $this->send($emails, "Re: {$thread->subject}", $text, $message->sender_email, $message->sender_name);
    }

    /** The text, and a note when images came with it (they are viewed in the app, never attached to the e-mail). */
    private function content(ThreadMessage $message): string
    {
        $images = $message->attachments()->count();

        return trim($message->body.($images ? "\n\n[".app(ThreadAttachments::class)->description($images).' - open the conversation to see '.($images === 1 ? 'it' : 'them').']' : ''));
    }

    /** @param  list<string>  $to */
    private function send(array $to, string $subject, string $text, string $replyTo, string $replyToName): void
    {
        foreach (array_unique($to) as $email) {
            try {
                Mail::raw($text, fn ($m) => $m->to($email)->replyTo($replyTo, $replyToName)->subject($subject));
            } catch (Throwable) {
                // see the class note: the message is already in the inbox
            }
        }
    }
}
