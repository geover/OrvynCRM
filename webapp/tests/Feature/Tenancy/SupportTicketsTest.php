<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\Support\Tickets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** Support tickets: reported by a company's administrators, worked on by the platform's, converted from Messages, closed by themselves when resolved and quiet. */
class SupportTicketsTest extends TenantTestCase
{
    private Tenant $alpha;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('platform');
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
        $this->beta = $this->company('Beta', 'owner@beta.test');
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
    }

    private function as(string $email, string $password, string $company): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $password, 'company' => $company])->assertOk()->json('token');

        return $this->withToken($token);
    }

    private function asAdmin(): static
    {
        return $this->as('admin@alpha.test', 'Owner-Passw0rd-1', 'alpha');
    }

    private function asPlatform(): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        return $this;
    }

    private function open(array $extra = []): int
    {
        return $this->asAdmin()->postJson('/api/v1/support-tickets', ['subject' => 'Import fails', 'category' => 'bug', 'priority' => 'high', 'body' => '<p>It stops at <strong>50%</strong>.</p>'] + $extra)
            ->assertCreated()->json('data.id');
    }

    public function test_every_administrator_of_the_company_sees_a_ticket_and_nobody_else_does(): void
    {
        $this->asAdmin()->postJson('/api/v1/users', ['name' => 'Second Admin', 'email' => 'second@alpha.test', 'password' => 'Second-Passw0rd-1', 'role' => 'admin'])->assertCreated();
        $this->asAdmin()->postJson('/api/v1/users', ['name' => 'Plain User', 'email' => 'user@alpha.test', 'password' => 'Plain-Passw0rd-1', 'role' => 'user'])->assertCreated();

        $id = $this->open(['context' => ['page' => 'https://app.test/contacts', 'browser' => 'Chrome', 'evil' => 'ignored']]);

        $this->as('second@alpha.test', 'Second-Passw0rd-1', 'alpha')->getJson('/api/v1/support-tickets')->assertOk()->assertJsonPath('data.0.reference', 'T-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT));
        $this->as('second@alpha.test', 'Second-Passw0rd-1', 'alpha')->getJson("/api/v1/support-tickets/$id")->assertOk()->assertJsonPath('data.opened_by', 'Alpha Owner');
        $this->as('user@alpha.test', 'Plain-Passw0rd-1', 'alpha')->getJson('/api/v1/support-tickets')->assertForbidden();                    // administrators only
        $this->as('user@alpha.test', 'Plain-Passw0rd-1', 'alpha')->postJson('/api/v1/support-tickets', ['subject' => 'x', 'category' => 'bug', 'priority' => 'low', 'body' => 'x'])->assertForbidden();

        $this->as('owner@beta.test', 'Owner-Passw0rd-1', 'beta')->getJson('/api/v1/support-tickets')->assertOk()->assertJsonCount(0, 'data');   // another company has none
        $this->as('owner@beta.test', 'Owner-Passw0rd-1', 'beta')->getJson("/api/v1/support-tickets/$id")->assertNotFound();

        $ticket = Ticket::find($id);
        $this->assertSame(['page' => 'https://app.test/contacts', 'browser' => 'Chrome', 'role' => 'admin'], array_diff_key($ticket->context, ['reported_at' => 1]));   // only known keys
        $this->asAdmin()->postJson('/api/v1/support-tickets', ['subject' => 'Empty', 'category' => 'bug', 'priority' => 'low', 'body' => '<p><br></p>'])->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_replies_move_the_turn_between_the_sides_and_a_private_note_never_reaches_the_company(): void
    {
        $id = $this->open();
        $this->asPlatform()->getJson("/platform/tickets/$id")->assertOk();                                                           // opening it reads it
        $this->assertFalse(Ticket::find($id)->unread_platform);

        $this->asPlatform()->postJson("/platform/tickets/$id/reply", ['body' => '<p>Looking into it.</p>'])->assertOk();
        $t = Ticket::find($id);
        $this->assertSame('waiting', $t->status);                                                                                     // the company's turn
        $this->assertTrue($t->unread_tenant);
        $this->assertNotNull($t->first_response_at);
        $this->assertNotNull($t->assignee_id);                                                                                        // whoever answers first takes it

        $this->asPlatform()->postJson("/platform/tickets/$id/reply", ['body' => 'Private: customer is on the old plan', 'note' => true])->assertOk();
        $company = $this->asAdmin()->getJson("/api/v1/support-tickets/$id")->assertOk();
        $this->assertSame(['reply', 'reply'], array_column($company->json('data.replies'), 'kind'));                                  // the note is not in what the company reads
        $this->assertStringNotContainsString('Private', $company->content());
        $this->assertFalse(Ticket::find($id)->unread_tenant);                                                                         // reading it reads it

        $this->asAdmin()->postJson("/api/v1/support-tickets/$id/replies", ['body' => 'It still fails.'])->assertCreated();
        $t = Ticket::find($id)->refresh();
        $this->assertSame('open', $t->status);                                                                                        // the platform's turn again
        $this->assertTrue($t->unread_platform);
        $this->assertSame(3, $this->asPlatform()->getJson("/platform/tickets/$id")->json('data.replies.2') === null ? 0 : 3);
        $this->assertCount(4, $this->asPlatform()->getJson("/platform/tickets/$id")->json('data.replies'));                           // two replies, one note, one from the company's follow-up (first + 3)
    }

    public function test_resolved_tickets_come_back_when_the_company_writes_and_can_be_closed_and_reopened(): void
    {
        $id = $this->open();
        $this->asPlatform()->patchJson("/platform/tickets/$id", ['status' => 'resolved', 'priority' => 'urgent'])->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.priority', 'urgent');
        $this->assertNotNull(Ticket::find($id)->resolved_at);

        $this->asAdmin()->postJson("/api/v1/support-tickets/$id/replies", ['body' => 'Not fixed.'])->assertCreated();
        $t = Ticket::find($id);
        $this->assertSame(['open', 1], [$t->status, $t->reopened_count]);                                                             // a reply reopens it
        $this->assertNull($t->resolved_at);

        $this->asAdmin()->postJson("/api/v1/support-tickets/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->asAdmin()->postJson("/api/v1/support-tickets/$id/reopen")->assertOk()->assertJsonPath('data.status', 'open');
        $this->asAdmin()->postJson("/api/v1/support-tickets/$id/reopen")->assertUnprocessable();                                      // it is not closed
        $this->asPlatform()->patchJson("/platform/tickets/$id", ['status' => 'nonsense'])->assertUnprocessable();
    }

    public function test_files_can_be_attached_and_a_note_s_file_is_never_served_to_the_company(): void
    {
        $id = $this->open(['attachments' => [UploadedFile::fake()->image('screen.png', 200, 120)]]);
        $this->asAdmin()->postJson('/api/v1/support-tickets', ['subject' => 'Bad file', 'category' => 'bug', 'priority' => 'low', 'body' => 'x', 'attachments' => [UploadedFile::fake()->create('run.exe', 5)]])->assertUnprocessable();

        $attachment = TicketAttachment::first();
        $this->assertSame('image/png', $attachment->mime);
        Storage::disk('platform')->assertExists($attachment->path);
        $this->asAdmin()->get("/files/ticket-attachments/{$attachment->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->asPlatform()->get("/platform/tickets/attachments/{$attachment->id}")->assertOk();

        $this->asPlatform()->post("/platform/tickets/$id/reply", ['body' => 'internal', 'note' => '1', 'attachments' => [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')]], ['Accept' => 'application/json'])->assertOk();
        $private = TicketAttachment::where('mime', 'text/plain')->first();
        $this->asAdmin()->get("/files/ticket-attachments/{$private->id}")->assertNotFound();                                          // a platform note's file stays inside the platform
    }

    public function test_a_messages_conversation_becomes_a_ticket_with_its_history_and_says_so(): void
    {
        $thread = MessageThread::start($this->alpha->id, 'Emails not arriving', ['name' => 'Admin', 'email' => 'admin@alpha.test'], 'Our e-mails never arrive.');
        $thread->post('platform', ['name' => 'Platform Admin', 'email' => 'platform@x.test'], 'Can you send an example?');

        $res = $this->asPlatform()->postJson("/platform/messages/{$thread->id}/ticket", ['category' => 'bug', 'priority' => 'high'])->assertCreated();
        $ticket = Ticket::find($res->json('data.id'));
        $this->assertSame([$thread->id, 'Emails not arriving', 'bug', 'high'], [$ticket->thread_id, $ticket->subject, $ticket->category, $ticket->priority]);
        $this->assertSame($ticket->id, $thread->fresh()->ticket_id);
        $this->assertSame(['tenant', 'platform', 'system'], $ticket->replies()->pluck('side')->all());                              // the two messages, then what happened
        $this->assertStringContainsString($ticket->reference(), $thread->fresh()->messages()->reorder('id', 'desc')->first()->body);           // the conversation tells the company
        $this->assertTrue($thread->fresh()->unread_tenant);

        $this->asPlatform()->postJson("/platform/messages/{$thread->id}/ticket")->assertUnprocessable();                              // only once
        $this->asAdmin()->getJson("/api/v1/support-tickets/{$ticket->id}")->assertOk()->assertJsonPath('data.thread_id', $thread->id);
    }

    public function test_resolved_tickets_that_go_quiet_are_closed_by_themselves_and_the_platform_page_lists_everything(): void
    {
        $id = $this->open();
        $this->asPlatform()->patchJson("/platform/tickets/$id", ['status' => 'resolved'])->assertOk();
        $this->assertSame(0, app(Tickets::class)->autoClose());                                                                       // too soon
        $this->travel(4)->days();
        $this->assertSame(1, app(Tickets::class)->autoClose());
        $t = Ticket::find($id);
        $this->assertSame('closed', $t->status);
        $this->assertTrue($t->unread_tenant);                                                                                         // the company has something new to read

        $this->asPlatform()->get('/platform/tickets')->assertOk()->assertInertia(function ($p) use ($id) {
            $p->component('Platform/Tickets');
            $props = $p->toArray()['props'];
            $this->assertSame([$id], array_column($props['tickets'], 'id'));
            $this->assertSame('Alpha', $props['tickets'][0]['company']);
        });
        $this->asPlatform()->patchJson('/platform/tickets/settings', ['auto_close_days' => 0])->assertUnprocessable();
        $this->asPlatform()->patchJson('/platform/tickets/settings', ['auto_close_days' => 5, 'email_company' => false])->assertOk()->assertJsonPath('data.auto_close_days', 5);
    }

    public function test_the_badges_follow_new_tickets_and_replies_without_a_page_load(): void
    {
        $before = $this->asPlatform()->getJson('/platform/tickets/poll')->assertOk();
        $this->assertSame(0, $before->json('needing'));

        $id = $this->open();
        $after = $this->asPlatform()->getJson('/platform/tickets/poll')->assertOk();
        $this->assertSame(1, $after->json('needing'));                                                                                // a new ticket needs the platform
        $this->assertNotSame($before->json('sig'), $after->json('sig'));

        $this->asAdmin()->getJson('/api/v1/support-tickets/poll')->assertOk()->assertJsonPath('unread', 0);
        $this->asPlatform()->postJson("/platform/tickets/$id/reply", ['body' => '<p>On it.</p>'])->assertOk();
        $this->asAdmin()->getJson('/api/v1/support-tickets/poll')->assertOk()->assertJsonPath('unread', 1);                         // the company has a reply to read
    }
}
