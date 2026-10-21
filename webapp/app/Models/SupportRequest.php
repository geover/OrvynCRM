<?php

namespace App\Models;

use App\Support\SafeHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A "Contact support" message of this company: sent by e-mail to the internal support addresses and kept here so administrators can read it. */
class SupportRequest extends Model
{
    protected $guarded = [];

    /** What a person wrote, as safe HTML: formatted text is cleaned, plain text (an API client) keeps its line breaks. */
    public static function html(string $text): string
    {
        return preg_match('/<[a-z!\/][^>]*>/i', $text) ? (SafeHtml::clean($text) ?? '') : nl2br(e($text));
    }

    /** True when nothing is left of formatted text once the tags are gone (an empty editor). */
    public static function isBlank(string $html): bool
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) === '';
    }

    /** The company's choices: does a message to support e-mail the internal addresses, and does an answer e-mail the person (the app shows both either way). */
    public static function emailsAdmins(): bool
    {
        return (bool) Setting::get('support.email_admins', true);
    }

    public static function emailsPerson(): bool
    {
        return (bool) Setting::get('support.email_user', true);
    }

    /** What the person and the administrators see of one conversation. @return array<string, mixed> */
    public function conversation(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'subject' => $this->subject, 'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(), 'last_activity_at' => ($this->last_activity_at ?? $this->created_at)?->toIso8601String(),
            'replies' => $this->replies->map(fn ($a) => ['id' => $a->id, 'side' => $a->side, 'name' => $a->name, 'body' => $a->body, 'created_at' => $a->created_at?->toIso8601String()])->values(),
        ];
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportRequestReply::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return ['sent_to' => 'array', 'unread_admin' => 'boolean', 'unread_user' => 'boolean', 'last_activity_at' => 'datetime'];
    }
}
