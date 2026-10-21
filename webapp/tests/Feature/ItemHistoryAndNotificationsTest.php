<?php

namespace Tests\Feature;

use App\Jobs\NotifyOverdueTasks;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectAttachment;
use App\Models\DataObjectRelationship;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemHistoryAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DataObject $deals;

    private DataObjectRelationship $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
        $this->owner = DataObjectRelationship::create(['label' => 'Owner']);
        $this->deals = DataObject::create(['name' => 'Deals']);
        $this->deals->fields()->createMany([
            ['key' => 'title', 'label' => 'Deal title', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number'],
        ]);
        $this->deals->relationships()->attach($this->owner->id);
        $this->deals = $this->deals->fresh('fields');
    }

    public function test_item_history_records_field_level_diffs_and_shows_on_the_contact_timeline(): void
    {
        $c = Contact::create(['first_name' => 'Ann', 'email' => 'a@x.io']);
        $svc = app(RecordService::class);
        $r = $svc->create($this->deals, ['title' => 'Big', 'amount' => 100], null, [['contact_id' => $c->id, 'relationship_id' => $this->owner->id]]);
        $svc->update($r, ['amount' => 250, 'title' => 'Big']);     // title unchanged -> not in the diff
        $svc->update($r, ['amount' => 250]);                         // no-op -> no entry

        $h = $this->getJson("/api/v1/data-objects/{$this->deals->id}/records/{$r->id}/history")->assertOk()->json('data');
        $updates = collect($h)->where('type', 'record.updated');
        $this->assertCount(1, $updates);
        $this->assertSame([['field' => 'Amount', 'from' => 100, 'to' => 250]], $updates->first()['changes']);
        $this->assertContains('record.created', array_column($h, 'type'));

        $this->getJson("/api/v1/contacts/{$c->id}/timeline")->assertOk()->assertJsonFragment(['type' => 'record.updated']);
    }

    public function test_item_attachments_upload_download_delete_and_permissions(): void
    {
        $r = app(RecordService::class)->create($this->deals, ['title' => 'T']);
        $base = "/api/v1/data-objects/{$this->deals->id}/records/{$r->id}/attachments";

        $id = $this->post($base, ['file' => UploadedFile::fake()->create('spec.pdf', 12, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->post($base, ['file' => UploadedFile::fake()->create('x.exe', 1)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.filename', 'spec.pdf');
        $this->get("$base/$id")->assertOk();

        Sanctum::actingAs(User::factory()->create());                       // no template -> no data object permissions
        $this->getJson($base)->assertForbidden();
        $this->deleteJson("$base/$id")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("$base/$id")->assertNoContent();
        $this->assertSame(0, DataObjectAttachment::count());
    }

    public function test_notifications_are_produced_for_assignments_connections_and_overdue_digest(): void
    {
        $bob = User::factory()->create(['name' => 'Bob']);
        $bobContact = Contact::create(['first_name' => 'Bob', 'email' => 'bob@x.io']);
        $bob->update(['contact_id' => $bobContact->id]);

        // 1) task assigned to someone else
        $this->postJson('/api/v1/tasks', ['title' => 'Prepare the quote', 'assigned_to' => $bob->id, 'due_at' => now()->subDay()->toIso8601String()])->assertCreated();
        // 2) assigning to yourself does NOT notify
        $this->postJson('/api/v1/tasks', ['title' => 'Mine'])->assertCreated();
        // 3) connecting the user's own contact to an item
        app(RecordService::class)->create($this->deals, ['title' => 'X'], null, [['contact_id' => $bobContact->id, 'relationship_id' => $this->owner->id]]);

        $titles = DB::table('notifications_feed')->where('user_id', $bob->id)->pluck('title')->all();
        $this->assertContains('New task: Prepare the quote', $titles);
        $this->assertContains('You were connected to a Deals', $titles);
        $this->assertSame(0, DB::table('notifications_feed')->where('user_id', $this->admin->id)->count());

        // 4) overdue digest: one per user per day, idempotent
        app()->call([new NotifyOverdueTasks, 'handle']);
        app()->call([new NotifyOverdueTasks, 'handle']);
        $this->assertSame(1, DB::table('notifications_feed')->where('user_id', $bob->id)->where('title', 'You have 1 overdue task')->count());
    }

    public function test_support_request_emails_the_configured_addresses_and_copies_the_sender(): void
    {
        $this->postJson('/api/v1/support', ['subject' => 'Help', 'message' => 'It broke'])->assertUnprocessable();    // none configured

        Setting::put('support.internal_emails', ['support@acme.io', 'it@acme.io']);
        $c = Contact::create(['first_name' => 'A', 'email' => 'a@x.io']);
        $this->postJson('/api/v1/support', ['subject' => 'Help', 'message' => "Line 1\n<script>x</script>", 'contact_id' => $c->id])->assertAccepted()->assertJsonPath('sent', 2);

        $this->assertSame(2, EmailMessage::count());
        $m = EmailMessage::first();
        $this->assertSame('[Support] Help', $m->subject);
        $this->assertSame($this->admin->email, $m->cc[0]['email']);
        $this->assertStringNotContainsString('<script>', $m->html);               // user text is escaped
        $this->assertStringContainsString("/contacts/{$c->id}", $m->html);

        $this->getJson('/api/v1/support-requests')->assertOk()->assertJsonCount(1, 'data')                                  // kept for Messages > Internal support
            ->assertJsonPath('data.0.subject', 'Help')->assertJsonPath('data.0.sent_to', ['support@acme.io', 'it@acme.io'])->assertJsonPath('data.0.contact.id', $c->id);

        $id = $this->getJson('/api/v1/support-requests')->json('data.0.id');
        $this->postJson("/api/v1/support-requests/$id/replies", ['body' => ''])->assertUnprocessable();
        $this->postJson("/api/v1/support-requests/$id/replies", ['body' => '<p>We are <b>on</b> it.</p><script>x</script>'])->assertOk()->assertJsonPath('data.replies.0.name', $this->admin->name);
        $answer = EmailMessage::latest('id')->first();
        $this->assertSame('Re: [Support] Help', $answer->subject);
        $this->assertSame($this->admin->email, $answer->to[0]['email']);                                                  // the sender is answered by e-mail
        $this->assertStringContainsString('<b>on</b>', $answer->html);
        $this->assertStringNotContainsString('<script>', $answer->html);
    }

    public function test_contact_support_is_a_conversation_the_person_can_come_back_to(): void
    {
        Setting::put('support.internal_emails', ['support@acme.io']);
        $person = User::factory()->create();                                                                                // no administrator
        Sanctum::actingAs($person);
        $id = $this->postJson('/api/v1/support', ['subject' => 'Cannot export', 'message' => '<p>It <b>fails</b>.</p>'])->assertAccepted()->json('data.id');
        $this->postJson("/api/v1/support/$id/messages", ['body' => '<p>Still failing</p>'])->assertOk()->assertJsonCount(1, 'data.replies');
        $this->assertSame(2, EmailMessage::count());                                                                        // each message reached the internal address

        $this->getJson('/api/v1/support-requests')->assertForbidden();                                                      // reading all of them is for administrators
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/support/conversations')->assertOk()->assertJsonCount(0, 'data');                            // and the list is only the person's own
        $this->getJson('/api/v1/support-requests/poll')->assertOk()->assertJsonPath('unread', 1);
        $this->postJson("/api/v1/support-requests/$id/replies", ['body' => '<p>Try again now.</p>'])->assertOk();
        $this->getJson('/api/v1/support-requests/poll')->assertOk()->assertJsonPath('unread', 0);                           // answered: nothing waits for the administrators

        $this->assertSame(1, DB::table('notifications_feed')->where('user_id', $person->id)->where('title', 'Support answered: Cannot export')->count());   // the bell
        Sanctum::actingAs($person);
        $this->getJson('/api/v1/support/unread')->assertOk()->assertJsonPath('unread', 1);                                  // the badge
        $mine = $this->getJson('/api/v1/support/conversations')->assertOk()->assertJsonPath('data.0.unread', true)->json('data.0');
        $this->assertSame(['user', 'admin'], array_column($mine['replies'], 'side'));                                       // the whole conversation, both sides
        $this->postJson("/api/v1/support/$id/read")->assertOk();
        $this->getJson('/api/v1/support/conversations')->assertJsonPath('data.0.unread', false);
        $this->getJson('/api/v1/support/unread')->assertJsonPath('unread', 0);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/support/$id/messages", ['body' => 'not mine'])->assertNotFound();                          // somebody else's conversation
    }

    public function test_the_company_chooses_whether_support_messages_are_e_mailed(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/settings', ['support_email_admins' => false, 'support_email_user' => false])->assertOk()->assertJsonPath('data.support_email_admins', false);

        $id = $this->postJson('/api/v1/support', ['subject' => 'Quiet', 'message' => '<p>Only in the app</p>'])->assertAccepted()->assertJsonPath('sent', 0)->json('data.id');   // no addresses needed
        $this->postJson("/api/v1/support-requests/$id/replies", ['body' => '<p>Answer</p>'])->assertOk();
        $this->assertSame(0, EmailMessage::count());                                                                        // nothing was e-mailed, both sides still have it
        $this->getJson('/api/v1/support/conversations')->assertJsonPath('data.0.replies.0.body', '<p>Answer</p>');
    }
}
