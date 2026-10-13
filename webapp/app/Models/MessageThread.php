<?php

namespace App\Models;

use App\Services\Tenancy\ThreadAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A conversation between a company's administrators and the platform's administrators. A new message starts a new
 * thread; replies from either side stay in it.
 */
class MessageThread extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime', 'archived_at' => 'datetime', 'tenant_archived_at' => 'datetime', 'tenant_deleted_at' => 'datetime',
            'unread_platform' => 'boolean', 'unread_tenant' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // the rows go with the thread (cascade); the image files have to be removed by hand
        static::deleting(function (MessageThread $thread) {
            ThreadAttachment::whereIn('message_id', ThreadMessage::where('thread_id', $thread->id)->pluck('id'))->get()->each->deleteFile();
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class, 'thread_id')->orderBy('id');
    }

    /** What a company sees: its own threads, minus the ones it deleted. */
    public function scopeVisibleToTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId)->whereNull('tenant_deleted_at');
    }

    /** Waiting for a platform administrator (archived threads never count). */
    public function scopeUnreadForPlatform(Builder $query): Builder
    {
        return $query->where('unread_platform', true)->whereNull('archived_at');
    }

    /** Waiting for the company's administrators. */
    public function scopeUnreadForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->visibleToTenant($tenantId)->where('unread_tenant', true);
    }

    /**
     * Add a message and update the thread: the other side now has something unread. A reply from the company brings
     * an archived thread back into the platform's inbox.
     *
     * @param  array{account_id?: int|null, name: string, email: string}  $sender
     * @param  list<UploadedFile>  $files
     */
    public function post(string $type, array $sender, ?string $body, array $files = []): ThreadMessage
    {
        $message = $this->messages()->create([
            'sender_type' => $type, 'sender_account_id' => $sender['account_id'] ?? null,
            'sender_name' => $sender['name'], 'sender_email' => $sender['email'], 'body' => trim((string) $body),
        ]);
        $files && app(ThreadAttachments::class)->store($message, $files);

        $this->forceFill([
            'last_message_at' => now(),
            'unread_platform' => $type === 'tenant', 'unread_tenant' => $type === 'platform',
        ] + ($type === 'tenant' ? ['archived_at' => null] : ['tenant_archived_at' => null]))->save();       // each side's new message surfaces the thread for the other

        return $message;
    }

    /**
     * Start a new thread with its first message.
     *
     * @param  array{account_id?: int|null, name: string, email: string}  $sender
     * @param  list<UploadedFile>  $files
     */
    public static function start(string $tenantId, string $subject, array $sender, ?string $body, array $files = []): self
    {
        $thread = static::create([
            'tenant_id' => $tenantId, 'subject' => trim($subject), 'started_by_name' => $sender['name'], 'started_by_email' => $sender['email'],
            'last_message_at' => now(),
        ]);
        $thread->post('tenant', $sender, $body, $files);

        return $thread;
    }
}
