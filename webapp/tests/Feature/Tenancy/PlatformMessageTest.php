<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\MessageThread;
use App\Models\ThreadAttachment;
use App\Models\ThreadMessage;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\SafeHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** Conversations between company administrators and platform administrators: a new message starts a thread, replies stay in it. */
class PlatformMessageTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function api(string $email, string $company, string $password = 'Owner-Passw0rd-1'): static
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $password, 'company' => $company])->json('token');
        $client = $this->withToken($token);
        $this->defaultHeaders = [];

        return $client->withToken($token);
    }

    private function platformAdmin(string $email = 'platform@x.test', string $name = 'Platform Admin'): void
    {
        AccountUser::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => $email, 'password' => 'Platform-Passw0rd-1']);
    }

    private function startThread(string $email = 'owner@acme.test', string $company = 'acme', string $subject = 'Need a custom domain'): int
    {
        return $this->api($email, $company)->postJson('/api/v1/platform-threads', ['subject' => $subject, 'body' => "Hello,\nCan we use app.acme.test?"])->assertCreated()->json('data.id');
    }

    public function test_a_new_message_starts_a_thread_that_names_who_wrote_it(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();

        $thread = MessageThread::findOrFail($id);
        $this->assertSame(['Need a custom domain', 'Acme Owner', true, false], [$thread->subject, $thread->started_by_name, $thread->unread_platform, $thread->unread_tenant]);
        $message = ThreadMessage::where('thread_id', $id)->firstOrFail();
        $this->assertSame(['tenant', 'Acme Owner', 'owner@acme.test'], [$message->sender_type, $message->sender_name, $message->sender_email]);

        $this->api('owner@acme.test', 'acme')->getJson("/api/v1/platform-threads/{$id}")->assertOk()
            ->assertJsonPath('data.messages.0.name', 'Acme Owner')->assertJsonPath('data.messages.0.from', 'tenant')->assertJsonPath('data.subject', 'Need a custom domain');
    }

    public function test_replies_stay_in_the_same_thread_and_a_new_message_makes_another(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $first = $this->startThread();
        $this->platformAdmin();

        $this->post("/platform/messages/{$first}/reply", ['body' => 'Yes, send us the domain.'])->assertRedirect()->assertSessionHas('success', 'Reply sent.');
        $this->api('owner@acme.test', 'acme')->postJson("/api/v1/platform-threads/{$first}/messages", ['body' => 'It is crm.acme.test'])->assertCreated()->assertJsonCount(3, 'data.messages');

        $this->assertSame(1, MessageThread::count());                                                          // still one thread
        $this->assertSame(['tenant', 'platform', 'tenant'], ThreadMessage::where('thread_id', $first)->orderBy('id')->pluck('sender_type')->all());

        $second = $this->startThread(subject: 'A different question');                                         // a new message: a new thread
        $this->assertNotSame($first, $second);
        $this->assertSame(2, MessageThread::count());
        $this->assertSame(1, ThreadMessage::where('thread_id', $second)->count());
    }

    public function test_the_platform_reply_shows_who_wrote_it_to_the_company(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->platformAdmin('platform@x.test', 'Pat Platform');
        $this->post("/platform/messages/{$id}/reply", ['body' => 'Looking into it.'])->assertRedirect();

        $thread = $this->api('owner@acme.test', 'acme')->getJson("/api/v1/platform-threads/{$id}")->assertOk()->json('data.messages');
        $this->assertSame(['tenant', 'platform'], array_column($thread, 'from'));
        $this->assertSame(['Acme Owner', 'Pat Platform'], array_column($thread, 'name'));
        $this->assertSame('platform@x.test', $thread[1]['email']);
    }

    public function test_unread_flags_follow_who_wrote_last_and_who_opened_it(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->platformAdmin();
        $this->get('/platform/messages')->assertInertia(fn ($p) => $p->where('platformMessagesUnread', 1)->where('threads.0.unread', true)->where('selected', null));
        $this->get("/platform/messages?thread={$id}")->assertInertia(fn ($p) => $p->where('selected.messages', fn ($m) => count($m) === 1));         // opening reads it
        $this->get('/platform/messages')->assertInertia(fn ($p) => $p->where('platformMessagesUnread', 0));

        $this->post("/platform/messages/{$id}/reply", ['body' => 'Hi'])->assertRedirect();
        $this->assertSame([false, true], [MessageThread::find($id)->unread_platform, MessageThread::find($id)->unread_tenant]);
        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/platform-threads')->assertJsonPath('unread', 1)->assertJsonPath('data.0.unread', true);
        $this->get('/')->assertOk();

        $this->api('owner@acme.test', 'acme')->getJson("/api/v1/platform-threads/{$id}")->assertOk();                  // the company opens it
        $this->assertFalse(MessageThread::find($id)->unread_tenant);
        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/platform-threads')->assertJsonPath('unread', 0);

        $this->platformAdmin();
        $this->post("/platform/messages/{$id}/read", ['read' => false])->assertRedirect();                           // keep it unread to come back to
        $this->assertTrue(MessageThread::find($id)->unread_platform);
        $this->get('/platform/dashboard')->assertInertia(fn ($p) => $p->where('kpis.messages_unread', 1));
    }

    public function test_companies_only_see_and_write_in_their_own_threads(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $acmeThread = $this->startThread();

        $this->api('owner@beta.test', 'beta')->getJson('/api/v1/platform-threads')->assertJsonCount(0, 'data');
        $this->api('owner@beta.test', 'beta')->getJson("/api/v1/platform-threads/{$acmeThread}")->assertNotFound();
        $this->api('owner@beta.test', 'beta')->postJson("/api/v1/platform-threads/{$acmeThread}/messages", ['body' => 'sneaky'])->assertNotFound();
        $this->assertSame(1, ThreadMessage::count());
    }

    public function test_any_administrator_of_the_company_can_join_the_thread_but_nobody_else(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $owner = fn () => $this->api('owner@acme.test', 'acme');
        $owner()->postJson('/api/v1/users', ['name' => 'Second Admin', 'email' => 'admin2@acme.test', 'password' => 'Second-Passw0rd-1', 'role' => 'admin'])->assertCreated();
        $owner()->postJson('/api/v1/users', ['name' => 'Rae Regular', 'email' => 'rae@acme.test', 'password' => 'Regular-Passw0rd-1', 'role' => 'user'])->assertCreated();
        $owner()->postJson('/api/v1/users', ['name' => 'Mia Manager', 'email' => 'mia@acme.test', 'password' => 'Manager-Passw0rd-1', 'role' => 'manager'])->assertCreated();

        $this->api('admin2@acme.test', 'acme', 'Second-Passw0rd-1')->postJson("/api/v1/platform-threads/{$id}/messages", ['body' => 'I will add: we also need SSL.'])->assertCreated()
            ->assertJsonPath('data.messages.1.name', 'Second Admin');                                                    // the thread shows which administrator wrote

        foreach (['rae@acme.test' => 'Regular-Passw0rd-1', 'mia@acme.test' => 'Manager-Passw0rd-1'] as $email => $password) {
            $user = fn () => $this->api($email, 'acme', $password);
            $user()->getJson('/api/v1/platform-threads')->assertForbidden();
            $user()->getJson("/api/v1/platform-threads/{$id}")->assertForbidden();
            $user()->postJson('/api/v1/platform-threads', ['subject' => 'Hi', 'body' => 'Hi'])->assertForbidden();
            $user()->postJson("/api/v1/platform-threads/{$id}/messages", ['body' => 'Hi'])->assertForbidden();
        }
        $this->assertSame(2, ThreadMessage::count());
    }

    public function test_validation(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $owner = fn () => $this->api('owner@acme.test', 'acme');
        $owner()->postJson('/api/v1/platform-threads', ['subject' => '', 'body' => ''])->assertUnprocessable()->assertJsonValidationErrors(['subject', 'body']);
        $owner()->postJson('/api/v1/platform-threads', ['subject' => str_repeat('x', 161), 'body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $id = $this->startThread();
        $owner()->postJson("/api/v1/platform-threads/{$id}/messages", ['body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->platformAdmin();
        $this->post("/platform/messages/{$id}/reply", ['body' => ''])->assertSessionHasErrors('body');
        $this->post("/platform/messages/{$id}/reply", ['body' => str_repeat('x', 20001)])->assertSessionHasErrors('body');
    }

    public function test_a_platform_support_profile_does_not_write_as_the_company(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $platform = AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        app(TenantProvisioner::class)->addSupportProfile($acme, $platform);

        $this->assertSame(0, MessageThread::count());
        $this->assertNotNull($acme);
    }

    public function test_archive_restore_delete_and_a_company_reply_brings_a_thread_back(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->platformAdmin();

        $this->delete("/platform/messages/{$id}")->assertUnprocessable();                                       // not archived yet
        $this->post("/platform/messages/{$id}/archive")->assertRedirect()->assertSessionHas('success', 'Archived.');
        $this->assertFalse(MessageThread::find($id)->unread_platform, 'archived means dealt with');
        $this->get('/platform/messages')->assertInertia(fn ($p) => $p->where('platformMessagesUnread', 0)->where('threads.0.archived_at', fn ($v) => $v !== null));
        $this->post("/platform/messages/{$id}/reply", ['body' => 'x'])->assertUnprocessable();                  // restore before replying

        $this->post("/platform/messages/{$id}/unarchive")->assertRedirect()->assertSessionHas('success', 'Restored.');
        $this->post("/platform/messages/{$id}/archive");
        $this->api('owner@acme.test', 'acme')->postJson("/api/v1/platform-threads/{$id}/messages", ['body' => 'Any news?'])->assertCreated();   // the company writes again...
        $this->assertNull(MessageThread::find($id)->archived_at);                                                // ...so it is back in the inbox
        $this->assertTrue(MessageThread::find($id)->unread_platform);

        $this->platformAdmin();
        $this->post("/platform/messages/{$id}/archive");
        $this->delete("/platform/messages/{$id}")->assertRedirect()->assertSessionHas('success', 'Deleted.');
        $this->assertNull(MessageThread::find($id));
        $this->assertSame(0, ThreadMessage::count(), 'the messages go with the thread');
    }

    public function test_company_admins_cannot_open_the_platform_side(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->get('/platform/messages')->assertForbidden();
        $this->post("/platform/messages/{$id}/reply", ['body' => 'I am the platform'])->assertForbidden();
        $this->post("/platform/messages/{$id}/archive")->assertForbidden();
        $this->delete("/platform/messages/{$id}")->assertForbidden();
        $this->assertSame(1, ThreadMessage::count());
        $this->get('/messages')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/PlatformMessages'));
        $this->get('/admin/platform-messages?thread=5')->assertRedirect('/messages?thread=5');                       // the old addresses forward
        $this->get('/admin/tickets?ticket=7')->assertRedirect('/tickets?ticket=7');
        $this->get('/tickets')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Tickets'));
    }

    public function test_the_old_one_way_messages_became_one_message_threads(): void
    {
        // the migration moved them; what remains is that every thread has its first message from the company
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->assertSame('tenant', ThreadMessage::where('thread_id', $id)->orderBy('id')->value('sender_type'));
    }

    public function test_opening_a_thread_returns_its_messages_to_the_company(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->platformAdmin();
        $this->post("/platform/messages/{$id}/reply", ['body' => 'Reply'])->assertRedirect();

        $this->api('owner@acme.test', 'acme')->getJson("/api/v1/platform-threads/{$id}")->assertOk()
            ->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.1.attachments', []);
    }

    public function test_the_company_can_archive_restore_and_delete_a_conversation_from_its_own_view(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $owner = fn () => $this->api('owner@acme.test', 'acme');

        $owner()->deleteJson("/api/v1/platform-threads/{$id}")->assertUnprocessable();                              // archive first
        $owner()->postJson("/api/v1/platform-threads/{$id}/archive")->assertOk()->assertJsonPath('archived', true);
        $owner()->getJson('/api/v1/platform-threads')->assertJsonPath('data.0.archived', true);
        $owner()->postJson("/api/v1/platform-threads/{$id}/unarchive")->assertOk();
        $owner()->postJson("/api/v1/platform-threads/{$id}/archive");

        $this->platformAdmin();
        $this->post("/platform/messages/{$id}/reply", ['body' => 'Anything else?'])->assertRedirect();               // a platform reply brings it back
        $this->assertNull(MessageThread::find($id)->tenant_archived_at);

        $owner()->postJson("/api/v1/platform-threads/{$id}/archive");
        $owner()->deleteJson("/api/v1/platform-threads/{$id}")->assertNoContent();
        $owner()->getJson('/api/v1/platform-threads')->assertJsonCount(0, 'data');
        $owner()->getJson("/api/v1/platform-threads/{$id}")->assertNotFound();
        $this->assertNotNull(MessageThread::find($id), 'the platform keeps its record');

        $this->platformAdmin();
        $this->get('/platform/messages')->assertInertia(fn ($p) => $p->where('threads.0.company_deleted', true));
        $this->post("/platform/messages/{$id}/reply", ['body' => 'Hello?'])->assertUnprocessable();                   // nobody would see it
    }

    public function test_images_can_be_attached_by_both_sides_and_only_the_right_people_can_open_them(): void
    {
        Storage::fake('platform');
        $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $image = fn (string $name = 'shot.png') => UploadedFile::fake()->image($name, 120, 80);

        $id = $this->api('owner@acme.test', 'acme')->post('/api/v1/platform-threads', ['subject' => 'Screenshot', 'attachments' => [$image()]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(1, 'data.messages.0.attachments')->json('data.id');            // an image alone is a message
        $attachment = ThreadMessage::where('thread_id', $id)->firstOrFail()->attachments()->firstOrFail();
        Storage::disk('platform')->assertExists($attachment->path);
        $this->assertSame('image/png', $attachment->mime);

        // the company that wrote it opens it; another company and ordinary users cannot
        $this->api('owner@acme.test', 'acme');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get("/files/thread-attachments/{$attachment->id}")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        tenancy()->end();                                                      // a real request is a new process: no company is open yet
        $this->defaultHeaders = [];                                            // and carries no API token of the previous person
        $this->post('/login', ['email' => 'owner@beta.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get("/files/thread-attachments/{$attachment->id}")->assertNotFound();

        // the platform replies with an image of its own and opens the company's
        $this->platformAdmin();
        $this->get("/platform/attachments/{$attachment->id}")->assertOk();
        $this->post("/platform/messages/{$id}/reply", ['body' => 'Seen it', 'attachments' => [$image('answer.jpg')]])->assertRedirect()->assertSessionHas('success', 'Reply sent.');
        $this->assertSame(2, ThreadMessage::where('thread_id', $id)->count());
        $this->assertSame(2, ThreadAttachment::count());

        // deleting the thread removes the files too
        $paths = ThreadAttachment::pluck('path');
        $this->post("/platform/messages/{$id}/archive");
        $this->delete("/platform/messages/{$id}")->assertRedirect();
        $paths->each(fn ($path) => Storage::disk('platform')->assertMissing($path));
        $this->assertSame(0, ThreadAttachment::count());
    }

    public function test_attachments_are_limited_to_a_few_real_images(): void
    {
        Storage::fake('platform');
        $this->company('Acme', 'owner@acme.test');
        $post = fn (array $attachments, string $body = 'x') => $this->api('owner@acme.test', 'acme')->post('/api/v1/platform-threads', ['subject' => 'Files', 'body' => $body, 'attachments' => $attachments], ['Accept' => 'application/json']);

        $post([UploadedFile::fake()->create('evil.svg', 2, 'image/svg+xml')])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $post([UploadedFile::fake()->create('notes.pdf', 5, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $post([UploadedFile::fake()->create('huge.png', 6000, 'image/png')])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $post(array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.png"), range(1, 5)))->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->api('owner@acme.test', 'acme')->postJson('/api/v1/platform-threads', ['subject' => 'Nothing'])->assertUnprocessable()->assertJsonValidationErrors('body');   // no text and no image
        $this->assertSame(0, MessageThread::count());
    }

    public function test_the_company_poll_is_quiet_until_something_changes_then_returns_only_new_messages(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $client = $this->api('owner@acme.test', 'acme');

        $first = $client->getJson("/api/v1/platform-threads/poll?thread={$id}")->assertOk()->assertJsonPath('changed', true)->assertJsonCount(1, 'threads');
        $sig = $first->json('sig');
        $client->getJson("/api/v1/platform-threads/poll?sig={$sig}&thread={$id}")->assertOk()->assertJsonPath('changed', false);

        $this->platformAdmin();
        $this->post("/platform/messages/{$id}/reply", ['body' => 'Live reply']);
        $lastSeen = ThreadMessage::where('thread_id', $id)->orderBy('id')->first()->id;

        $poll = $this->api('owner@acme.test', 'acme')->getJson("/api/v1/platform-threads/poll?sig={$sig}&thread={$id}&after={$lastSeen}")->assertOk()
            ->assertJsonPath('changed', true)->assertJsonCount(1, 'open.messages')->assertJsonPath('open.messages.0.body', 'Live reply')->assertJsonPath('open.messages.0.from', 'platform');
        $this->assertFalse(MessageThread::findOrFail($id)->unread_tenant, 'reading it through the open thread marks it read');
        $this->assertSame(0, $poll->json('unread'));
    }

    public function test_the_company_poll_counts_unread_replies_for_the_menu_badge(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->platformAdmin();
        $this->post("/platform/messages/{$id}/reply", ['body' => 'Hello back']);

        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/platform-threads/poll?counts=1')->assertOk()->assertExactJson(['unread' => 1]);
    }

    public function test_the_company_poll_never_shows_another_companys_threads_or_a_plain_users(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $mine = $this->startThread();
        $theirs = $this->startThread('owner@beta.test', 'beta', 'Beta secret');

        $this->api('owner@acme.test', 'acme')->getJson("/api/v1/platform-threads/poll?thread={$theirs}")->assertOk()
            ->assertJsonCount(1, 'threads')->assertJsonPath('threads.0.id', $mine)->assertJsonPath('open', null);
        $this->api('owner@acme.test', 'acme')->getJson('/api/v1/platform-threads/poll?counts=1')->assertOk();
    }

    public function test_the_platform_poll_reports_changes_and_new_company_messages(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $id = $this->startThread();
        $this->platformAdmin();

        $first = $this->getJson('/platform/messages/poll')->assertOk()->assertJsonPath('changed', true)->assertJsonPath('unread', 1);
        $sig = $first->json('sig');
        $this->getJson("/platform/messages/poll?sig={$sig}&thread={$id}")->assertOk()->assertJsonPath('changed', false);

        $seen = ThreadMessage::where('thread_id', $id)->max('id');
        $this->api('owner@acme.test', 'acme')->postJson("/api/v1/platform-threads/{$id}/messages", ['body' => 'Anyone there?'])->assertCreated();
        $this->platformAdmin();

        $this->getJson("/platform/messages/poll?sig={$sig}&thread={$id}&after={$seen}")->assertOk()
            ->assertJsonPath('changed', true)->assertJsonCount(1, 'open.messages')->assertJsonPath('open.messages.0.body', 'Anyone there?');
        $this->assertFalse(MessageThread::findOrFail($id)->unread_platform);
        $this->getJson('/platform/messages/poll?counts=1')->assertOk()->assertExactJson(['unread' => 0]);
    }

    public function test_the_platform_poll_is_for_platform_administrators_only(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->flushSession();
        $this->getJson('/platform/messages/poll?counts=1')->assertRedirect();                 // not signed in as a platform administrator
    }

    public function test_messages_can_be_formatted_and_stay_safe(): void
    {
        $this->assertSame('<p>See <b>this</b></p>', MessageThread::normalise('<p>See <b>this</b></p><script>x()</script>'));
        $this->assertSame('', MessageThread::normalise('<p><br></p>'));                                    // an empty editor is nothing
        $this->assertSame("Plain text\nstays", MessageThread::normalise("Plain text\nstays "));
        $this->assertSame("Hello\nSecond & last", SafeHtml::toText('<p>Hello</p><p>Second &amp; last</p>'));      // the e-mail and the list preview read plain text
    }
}
