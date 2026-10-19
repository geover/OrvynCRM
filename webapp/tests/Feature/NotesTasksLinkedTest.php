<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactAttachment;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Notes and tasks of a contact: a task made from a note, the note logged when a task is completed, and files and links on either. */
class NotesTasksLinkedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
        Storage::fake('local');
        $this->contact = Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
    }

    private function note(string $body = 'Asked for a quote'): int
    {
        return $this->postJson("/api/v1/contacts/{$this->contact->id}/notes", ['title' => 'Call', 'body' => $body])->assertCreated()->json('data.id');
    }

    public function test_a_task_can_be_made_from_a_note_and_shows_both_ways(): void
    {
        $noteId = $this->note();
        $res = $this->postJson('/api/v1/tasks', ['title' => 'Send the quote', 'note_id' => $noteId])->assertCreated();
        $res->assertJsonPath('data.note_id', $noteId)->assertJsonPath('data.note.title', 'Call')->assertJsonPath('data.contact_id', $this->contact->id);   // the task takes the note's contact

        $this->getJson("/api/v1/tasks?filter[contact_id]={$this->contact->id}")->assertJsonPath('data.0.note.id', $noteId);

        // a note of another contact is refused
        $other = Contact::create(['first_name' => 'Bob', 'email' => 'bob@x.io']);
        $this->postJson('/api/v1/tasks', ['title' => 'x', 'contact_id' => $other->id, 'note_id' => $noteId])->assertUnprocessable();

        // the link can be changed and removed
        $second = $this->note('Second');
        $taskId = $res->json('data.id');
        $this->patchJson("/api/v1/tasks/$taskId", ['note_id' => $second])->assertOk()->assertJsonPath('data.note_id', $second);
        $this->patchJson("/api/v1/tasks/$taskId", ['note_id' => null])->assertOk()->assertJsonPath('data.note_id', null);
    }

    public function test_completing_a_task_writes_a_note_on_the_contact_that_points_back(): void
    {
        $task = Task::create(['title' => 'Call back', 'contact_id' => $this->contact->id, 'assigned_to' => $this->admin->id, 'created_by' => $this->admin->id]);

        $this->patchJson("/api/v1/tasks/{$task->id}", ['completed' => true, 'completion_note' => 'Left a message'])->assertOk();
        $note = Note::where('task_id', $task->id)->sole();
        $this->assertSame($this->contact->id, $note->contact_id);
        $this->assertSame('Task completed: Call back', $note->title);
        $this->assertStringContainsString($this->admin->name, $note->body);
        $this->assertStringContainsString('Left a message', $note->body);
        $this->assertSame(['task'], $note->tags);
        $this->getJson("/api/v1/contacts/{$this->contact->id}/notes")->assertJsonPath('data.0.task.title', 'Call back');

        // saving an already completed task again does not log it twice; reopening and completing again does
        $this->patchJson("/api/v1/tasks/{$task->id}", ['completed' => true])->assertOk();
        $this->assertSame(1, Note::where('task_id', $task->id)->count());
        $this->patchJson("/api/v1/tasks/{$task->id}", ['completed' => false])->assertOk();
        $this->patchJson("/api/v1/tasks/{$task->id}", ['completed' => true])->assertOk();
        $this->assertSame(2, Note::where('task_id', $task->id)->count());

        // a task with no contact has nowhere to write it
        $loose = Task::create(['title' => 'Loose', 'assigned_to' => $this->admin->id, 'created_by' => $this->admin->id]);
        $this->patchJson("/api/v1/tasks/{$loose->id}", ['completed' => true])->assertOk();
        $this->assertSame(0, Note::where('task_id', $loose->id)->count());
    }

    public function test_files_and_links_attach_to_notes_and_tasks_and_go_with_them(): void
    {
        $noteId = $this->note();
        $taskId = $this->postJson('/api/v1/tasks', ['title' => 'T', 'contact_id' => $this->contact->id])->assertCreated()->json('data.id');

        $file = $this->post("/api/v1/notes/$noteId/attachments", ['file' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $image = $this->post("/api/v1/tasks/$taskId/attachments", ['file' => UploadedFile::fake()->image('site.png')], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/v1/tasks/$taskId/attachments", ['url' => 'https://example.com/spec', 'title' => 'Spec'])->assertCreated();
        $this->postJson("/api/v1/notes/$noteId/attachments", ['url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->post("/api/v1/notes/$noteId/attachments", ['file' => UploadedFile::fake()->create('evil.php', 1)], ['Accept' => 'application/json'])->assertUnprocessable();

        // they come back on the note and the task, and on the contact's Attachments tab
        $this->getJson("/api/v1/contacts/{$this->contact->id}/notes")->assertJsonCount(1, 'data.0.attachments');
        $this->getJson("/api/v1/tasks?filter[contact_id]={$this->contact->id}")->assertJsonCount(2, 'data.0.attachments');
        $this->getJson("/api/v1/contacts/{$this->contact->id}/attachments")->assertJsonCount(3, 'data');

        // the file downloads; an image is served with its type
        $this->get('/api/v1/attachments/'.$file->json('data.id'))->assertOk();
        $this->assertTrue(Storage::disk('local')->exists(ContactAttachment::find($image->json('data.id'))->path));

        // one is removed on its own, the rest go with their note or task (files too)
        $this->deleteJson('/api/v1/attachments/'.$file->json('data.id'))->assertNoContent();
        $path = ContactAttachment::find($image->json('data.id'))->path;
        $this->deleteJson("/api/v1/tasks/$taskId")->assertNoContent();
        $this->assertSame(0, ContactAttachment::where('task_id', $taskId)->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_a_note_or_task_only_loses_the_link_not_the_other_one(): void
    {
        $noteId = $this->note();
        $taskId = $this->postJson('/api/v1/tasks', ['title' => 'T', 'note_id' => $noteId])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/contacts/{$this->contact->id}/notes/$noteId")->assertNoContent();
        $this->assertNull(Task::find($taskId)->note_id);
        $this->assertNotNull(Task::find($taskId));
    }

    public function test_a_task_without_a_contact_cannot_take_attachments(): void
    {
        $task = Task::create(['title' => 'Loose', 'assigned_to' => $this->admin->id, 'created_by' => $this->admin->id]);
        $this->postJson("/api/v1/tasks/{$task->id}/attachments", ['url' => 'https://example.com'])->assertUnprocessable();
    }
}
