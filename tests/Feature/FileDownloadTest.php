<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactAttachment;
use App\Models\DataTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Downloads are plain browser navigations, so they must work with the session cookie (web routes), not only bearer tokens. */
class FileDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_user_can_download_but_guests_are_sent_to_login(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('contacts/1/quote.pdf', '%PDF-1.4 test');
        $admin = User::factory()->create(['role' => 'admin']);
        $c = Contact::create(['first_name' => 'A', 'email' => 'a@x.io']);
        $a = ContactAttachment::create(['contact_id' => $c->id, 'title' => 'quote.pdf', 'disk' => 'local', 'path' => 'contacts/1/quote.pdf', 'mime' => 'application/pdf', 'size' => 12]);

        $this->get("/files/contacts/{$c->id}/attachments/{$a->id}")->assertRedirect('/login');           // guest
        $this->get("/files/contacts/{$c->id}/export")->assertRedirect('/login');

        $this->actingAs($admin, 'web');
        $this->get("/files/contacts/{$c->id}/attachments/{$a->id}")->assertOk()->assertDownload('quote.pdf');
        $this->get("/files/contacts/{$c->id}/export")->assertOk()->assertDownload("contact-{$c->id}.json");

        // wrong contact id for the attachment is a 404, not someone else's file
        $other = Contact::create(['first_name' => 'B', 'email' => 'b@x.io']);
        $this->get("/files/contacts/{$other->id}/attachments/{$a->id}")->assertNotFound();
    }

    public function test_export_files_are_admin_only_and_permissions_still_apply(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/1.csv', "id\n1\n");
        $t = DataTransfer::create(['direction' => 'export', 'resource' => 'contacts', 'status' => 'done', 'file_path' => 'exports/1.csv']);
        $c = Contact::create(['first_name' => 'A', 'email' => 'a@x.io']);

        $this->actingAs(User::factory()->create(), 'web');                                                   // no template, not admin
        $this->get("/files/transfers/{$t->id}")->assertNotFound();
        $this->get("/files/contacts/{$c->id}/export")->assertForbidden();                                    // lacks contacts.view

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
        $this->get("/files/transfers/{$t->id}")->assertOk();
    }
}
