<?php

namespace Tests\Feature;

use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\ContactRelationshipType;
use App\Models\DataObject;
use App\Models\DataObjectRelationship;
use App\Models\EmailMessage;
use App\Models\PermissionTemplate;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactRecordApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_settings_roundtrip_and_validation_and_logo(): void
    {
        $this->putJson('/api/v1/settings', ['timezone' => 'Europe/Berlin', 'color' => '#112233', 'internal_support_emails' => ['A@x.io', 'a@x.io', 'b@x.io'],
            'excluded_email_domains' => ['Spam.com']])->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Berlin')->assertJsonPath('data.color', '#112233')
            ->assertJsonPath('data.internal_support_emails', ['a@x.io', 'b@x.io'])->assertJsonPath('data.excluded_email_domains', ['spam.com']);

        $this->putJson('/api/v1/settings', ['color' => 'red'])->assertUnprocessable();
        $this->putJson('/api/v1/settings', ['timezone' => 'Mars/Base'])->assertUnprocessable();
        $this->putJson('/api/v1/settings', ['excluded_email_domains' => ['not a domain']])->assertUnprocessable();

        $this->post('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertExists(Setting::get('app.logo_path'));
        $this->post('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();   // SVG blocked

        Sanctum::actingAs(User::factory()->create());
        $this->putJson('/api/v1/settings', ['color' => '#000000'])->assertForbidden();
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonMissingPath('data.internal_support_emails');
    }

    public function test_timezone_saves_alone_is_shared_to_the_ui_immediately_and_ignores_a_bad_email_format(): void
    {
        $this->actingAs($this->admin);                                            // session user, so the Inertia share is exercised
        Cache::flush();

        // changing ONLY the timezone must work (the UI used to bundle an invalid email_format with it)
        $this->putJson('/api/v1/settings', ['timezone' => 'Asia/Manila'])->assertOk()->assertJsonPath('data.timezone', 'Asia/Manila');
        $this->putJson('/api/v1/settings', ['email_format' => 'ContactId@localhost:8000'])->assertUnprocessable();   // a port is not a valid hostname
        $this->putJson('/api/v1/settings', ['email_format' => 'ContactId@localhost'])->assertOk();
        $this->assertSame('Asia/Manila', Setting::get('app.timezone'));

        // the value the front end formats dates with is re-shared on the next page load (cache busted on save)
        $this->get('/')->assertOk()->assertSee('"timezone":"Asia\/Manila"', false);

        // scheduler helpers fall back to UTC for unknown ids
        Setting::put('app.timezone', 'Mars/Base');
        $this->artisan('schedule:list')->assertSuccessful();
    }

    public function test_tagline_is_a_company_setting_with_a_default_that_can_be_changed_or_cleared_but_never_reaches_the_sign_in_page(): void
    {
        Cache::flush();
        $this->getJson('/api/v1/settings')->assertJsonPath('data.tagline', 'Build. Connect. Automate.');          // default

        $this->putJson('/api/v1/settings', ['tagline' => '  Grow with us  '])->assertOk()->assertJsonPath('data.tagline', 'Grow with us');
        $this->putJson('/api/v1/settings', ['tagline' => str_repeat('x', 121)])->assertUnprocessable();

        // the sign-in page (a guest) keeps the platform's look: a company's tagline never reaches it
        auth()->guard('web')->logout();
        app('auth')->forgetGuards();
        $this->get('/login')->assertOk()->assertSee('"tagline":"Build. Connect. Automate."', false)->assertDontSee('Grow with us', false);

        // clearing it must STAY cleared (not silently fall back to the default)
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/settings', ['tagline' => ''])->assertOk()->assertJsonPath('data.tagline', '');
        $this->getJson('/api/v1/settings')->assertJsonPath('data.tagline', '');
        app('auth')->forgetGuards();
        $this->get('/login')->assertOk()->assertSee('"tagline":"Build. Connect. Automate."', false);
    }

    public function test_password_change_works_from_a_browser_session_and_revokes_api_tokens(): void
    {
        $user = User::factory()->withLogin('Old-Passw0rd-1')->create();
        $user->createToken('integration');
        app('auth')->forgetGuards();

        // plain cookie/session login (Sanctum "transient" token) - the path the web UI uses
        $this->actingAs($user, 'web')->withHeaders(['Referer' => config('app.url'), 'Origin' => config('app.url')]);
        $this->patchJson('/api/v1/me', ['current_password' => 'wrong', 'password' => 'New-Passw0rd-22'])->assertUnprocessable();
        $this->patchJson('/api/v1/me', ['current_password' => 'Old-Passw0rd-1', 'password' => 'New-Passw0rd-22'])->assertOk();

        $this->assertTrue(Hash::check('New-Passw0rd-22', AccountUser::find($user->central_user_id)->password));   // the password lives on the central identity
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_profile_password_change_and_personal_tokens(): void
    {
        Sanctum::actingAs(User::factory()->withLogin('password')->create());
        $this->patchJson('/api/v1/me', ['signature' => '<p>Cheers</p>', 'timezone' => 'Asia/Manila'])->assertOk()->assertJsonPath('data.signature', '<p>Cheers</p>');
        $this->patchJson('/api/v1/me', ['password' => 'NewPassw0rd!!', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->patchJson('/api/v1/me', ['password' => 'NewPassw0rd!!', 'current_password' => 'password'])->assertOk();
        $this->patchJson('/api/v1/me', ['password' => 'weak', 'current_password' => 'NewPassw0rd!!'])->assertUnprocessable();

        $plain = $this->postJson('/api/v1/me/tokens', ['name' => 'zapier'])->assertCreated()->json('token');
        $this->assertNotEmpty($plain);
        $this->getJson('/api/v1/me/tokens')->assertOk()->assertJsonPath('data.0.name', 'zapier')->assertJsonMissingPath('data.0.token');
    }

    public function test_user_admin_protects_last_admin_and_invites_by_email(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/users', ['name' => 'New', 'email' => 'new@x.io', 'role' => 'user'])->assertCreated()->assertJsonPath('invited', true);
        Notification::assertSentTo(AccountUser::where('email', 'new@x.io')->first(), ResetPassword::class);   // invitations go to the central identity

        $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'user'])->assertUnprocessable();   // sole admin
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['status' => 'suspended'])->assertUnprocessable();
        $this->deleteJson("/api/v1/users/{$this->admin->id}")->assertForbidden();                             // cannot delete self
    }

    public function test_user_fields_the_admin_screens_send_are_all_persisted(): void
    {
        $tpl = PermissionTemplate::create(['name' => 'T', 'permissions' => []]);
        $id = $this->postJson('/api/v1/users', ['name' => 'U', 'email' => 'u@x.io', 'role' => 'manager', 'permission_template_id' => $tpl->id, 'caller_id' => '+15551230000', 'password' => 'Passw0rd-12345'])
            ->assertCreated()->json('data.id');
        $this->assertSame('+15551230000', User::find($id)->caller_id);                                    // used to be silently dropped

        $this->patchJson("/api/v1/users/$id", ['name' => 'U2', 'role' => 'user', 'status' => 'suspended', 'caller_id' => '+15559999999', 'permission_template_id' => null])->assertOk();
        $u = User::find($id);
        $this->assertSame(['U2', 'user', 'suspended', '+15559999999', null], [$u->name, $u->role, $u->status, $u->caller_id, $u->permission_template_id]);

        $this->patchJson('/api/v1/me', ['name' => 'Me', 'timezone' => 'Asia/Manila', 'signature' => '<p>sig</p>'])->assertOk();
        $me = $this->admin->fresh();
        $this->assertSame(['Me', 'Asia/Manila', '<p>sig</p>'], [$me->name, $me->timezone, $me->signature]);
    }

    public function test_permission_templates_crud_and_guard(): void
    {
        $id = $this->postJson('/api/v1/permission-templates', ['name' => 'Sales', 'permissions' => ['contacts' => ['view', 'edit']], 'is_admin' => false])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/permission-templates', ['name' => 'Sales', 'permissions' => []])->assertUnprocessable();   // unique

        $user = User::factory()->create(['permission_template_id' => $id]);
        $this->deleteJson("/api/v1/permission-templates/$id")->assertUnprocessable();   // in use

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/v1/contacts')->assertOk();
        $this->postJson('/api/v1/contacts', ['first_name' => 'X'])->assertForbidden();   // only view+edit granted
    }

    public function test_direct_and_indirect_relationships(): void
    {
        $spouse = ContactRelationshipType::create(['label' => 'Spouse', 'kind' => 'direct']);
        [$a, $b, $c] = [Contact::create(['first_name' => 'A', 'email' => 'a@x.io']), Contact::create(['first_name' => 'B', 'email' => 'b@x.io']), Contact::create(['first_name' => 'C', 'email' => 'c@x.io'])];

        $this->postJson("/api/v1/contacts/{$a->id}/relationships", ['related_contact_id' => $b->id, 'type_id' => $spouse->id])->assertCreated();
        $this->postJson("/api/v1/contacts/{$a->id}/relationships", ['related_contact_id' => $a->id, 'type_id' => $spouse->id])->assertUnprocessable();   // self

        $this->getJson("/api/v1/contacts/{$b->id}/relationships")->assertOk()->assertJsonPath('direct.0.contact.id', $a->id)->assertJsonPath('direct.0.direction', 'in');

        // indirect: A and C share a Deals record
        $rel = DataObjectRelationship::create(['label' => 'Party']);
        $obj = DataObject::create(['name' => 'Deals']);
        $obj->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'text']);
        $record = app(RecordService::class)->create($obj->fresh('fields'), ['title' => 'T'], null, [['contact_id' => $a->id, 'relationship_id' => $rel->id], ['contact_id' => $c->id, 'relationship_id' => $rel->id]]);
        $this->getJson("/api/v1/contacts/{$a->id}/relationships")->assertOk()->assertJsonCount(1, 'indirect')->assertJsonPath('indirect.0.id', $c->id)->assertJsonPath('indirect.0.object', 'Deals');

        // contact tabs summary counts the linked record
        $this->getJson("/api/v1/contacts/{$a->id}/data-objects")->assertOk()->assertJsonPath('objects.0.count', 1);
    }

    public function test_attachments_upload_link_download_and_delete(): void
    {
        $c = Contact::create(['first_name' => 'A', 'email' => 'a@x.io']);
        $id = $this->post("/api/v1/contacts/{$c->id}/attachments", ['file' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/contacts/{$c->id}/attachments", ['url' => 'https://example.com/spec.pdf', 'title' => 'Spec'])->assertCreated();
        $this->postJson("/api/v1/contacts/{$c->id}/attachments", ['url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->post("/api/v1/contacts/{$c->id}/attachments", ['file' => UploadedFile::fake()->create('evil.php', 1)], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->getJson("/api/v1/contacts/{$c->id}/attachments")->assertOk()->assertJsonCount(2, 'data');
        $this->get("/api/v1/contacts/{$c->id}/attachments/$id")->assertOk();
        $this->deleteJson("/api/v1/contacts/{$c->id}/attachments/$id")->assertNoContent();
        $this->assertSame(1, $c->attachments()->count());
    }

    public function test_email_templates_merge_fields_and_composer_with_attachment_and_test_send(): void
    {
        $tpl = $this->postJson('/api/v1/email-templates', ['name' => 'Welcome', 'group' => 'Onboarding', 'subject' => 'Hi {{contact.first_name}}', 'html' => '<p>Hello</p>'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/email-templates?light=1')->assertOk()->assertJsonPath('groups.0', 'Onboarding')->assertJsonMissingPath('data.0.html');
        $this->postJson("/api/v1/email-templates/$tpl/duplicate")->assertCreated()->assertJsonPath('data.name', 'Welcome (copy)');
        $this->getJson('/api/v1/email-templates/merge-fields')->assertOk()->assertJsonPath('data.0.group', 'Contact');

        $this->admin->update(['signature' => '<i>-- Boss</i>']);
        $contact = Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
        $up = $this->post('/api/v1/uploads', ['file' => UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json();

        // cannot attach files outside the user's own temp folder
        $this->postJson("/api/v1/contacts/{$contact->id}/email", ['subject' => 'S', 'html' => '<p>x</p>', 'attachments' => [['path' => '../../.env', 'name' => 'x']]])->assertUnprocessable();
        $this->postJson("/api/v1/contacts/{$contact->id}/email", ['subject' => 'S', 'html' => '<p>x</p>', 'attachments' => [['path' => 'tmp/999/other.pdf', 'name' => 'x']]])->assertUnprocessable();

        // phpunit.xml uses the sync queue + array mailer, so this really renders, attaches and "sends"
        $this->postJson("/api/v1/contacts/{$contact->id}/email", ['subject' => 'Hi', 'html' => '<p>Body</p>', 'attachments' => [['path' => $up['path'], 'name' => 'brochure.pdf']]])->assertAccepted();
        $msg = EmailMessage::latest('id')->first();
        $this->assertStringContainsString('-- Boss', $msg->html);                  // signature appended
        $this->assertSame('sent', $msg->fresh()->status);
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('brochure.pdf', $sent->getAttachments()[0]->getFilename());
        $this->assertSame('ann@x.io', $sent->getTo()[0]->getAddress());

        $res = $this->postJson("/api/v1/contacts/{$contact->id}/email", ['subject' => 'Hi', 'html' => '<p>T</p>', 'test' => true])->assertAccepted();
        $this->assertSame($this->admin->email, $res->json('data.sent_to'));
        $this->getJson("/api/v1/messages/email/{$msg->id}")->assertOk()->assertJsonPath('data.subject', 'Hi');
    }

    public function test_tasks_default_to_the_creator_and_support_edit_complete_and_mine_filter(): void
    {
        $c = Contact::create(['first_name' => 'A', 'email' => 'a@x.io']);
        $id = $this->postJson('/api/v1/tasks', ['title' => 'Call', 'contact_id' => $c->id, 'assigned_to' => null, 'due_at' => null])->assertCreated()->json('data.id');
        $this->assertSame($this->admin->id, Task::find($id)->assigned_to);

        $this->getJson('/api/v1/tasks?filter[mine]=1&filter[status]=open')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/tasks/$id", ['title' => 'Call back', 'due_at' => now()->subDay()->toIso8601String()])->assertOk();
        $this->getJson('/api/v1/tasks?filter[status]=overdue')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/tasks/$id", ['completed' => true])->assertOk();
        $this->getJson('/api/v1/tasks?filter[status]=open')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tasks?filter[status]=done')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cannot_merge_or_relate_a_contact_with_itself(): void
    {
        $a = Contact::create(['first_name' => 'A', 'email' => 'a@x.io']);
        $type = ContactRelationshipType::create(['label' => 'Spouse', 'kind' => 'direct']);
        $this->postJson("/api/v1/contacts/{$a->id}/merge", ['duplicate_id' => $a->id])->assertUnprocessable();
        $this->postJson("/api/v1/contacts/{$a->id}/relationships", ['related_contact_id' => $a->id, 'type_id' => $type->id])->assertUnprocessable();
        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_notes_can_be_edited_by_author_only_and_tags_autocomplete(): void
    {
        $c = Contact::create(['first_name' => 'A', 'email' => 'a@x.io']);
        $nid = $this->postJson("/api/v1/contacts/{$c->id}/notes", ['body' => 'first', 'tags' => ['vip', 'call']])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/contacts/{$c->id}/notes/$nid", ['body' => 'edited', 'tags' => ['vip']])->assertOk()->assertJsonPath('data.body', 'edited');
        $this->getJson('/api/v1/note-tags')->assertOk()->assertJsonPath('data', ['vip']);

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/contacts/{$c->id}/notes/$nid", ['body' => 'hijack'])->assertForbidden();
    }

    public function test_notifications_inbox(): void
    {
        DB::table('notifications_feed')->insert([
            ['user_id' => $this->admin->id, 'title' => 'Task assigned', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->admin->id, 'title' => 'Another', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => User::factory()->create()->id, 'title' => 'Not mine', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 2)->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/notifications/read')->assertNoContent();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 0);
        $this->deleteJson('/api/v1/notifications')->assertNoContent();
        $this->assertSame(1, DB::table('notifications_feed')->count());   // other user's untouched
    }

    public function test_an_address_keeps_its_type_and_second_line(): void
    {
        $id = $this->postJson('/api/v1/contacts', ['first_name' => 'Ann', 'email' => 'ann@x.io', 'addresses' => [
            ['type' => 'SHIPPING', 'street1' => '1 Main St', 'street2' => 'Suite 4', 'city' => 'Springfield', 'country' => 'US'],
        ]])->assertCreated()->assertJsonPath('data.addresses.0.type', 'SHIPPING')->assertJsonPath('data.addresses.0.street2', 'Suite 4')->json('data.id');

        $this->patchJson("/api/v1/contacts/$id", ['addresses' => [['type' => 'OTHER', 'street1' => '2 Side Rd', 'street2' => '']]])
            ->assertOk()->assertJsonPath('data.addresses.0.type', 'OTHER');
    }

    public function test_deleting_a_relationship_disconnects_its_contacts_and_clears_what_named_it(): void
    {
        $owner = DataObjectRelationship::create(['label' => 'Owner']);
        $agent = DataObjectRelationship::create(['label' => 'Agent']);
        $object = DataObject::create(['name' => 'Homes']);
        $object->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'text']);
        $object->relationships()->attach([$owner->id, $agent->id]);
        $ann = Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
        $bob = Contact::create(['first_name' => 'Bob', 'email' => 'bob@x.io']);
        $record = app(RecordService::class)->create($object->fresh('fields'), ['title' => 'House'], null, [
            ['contact_id' => $ann->id, 'relationship_id' => $owner->id], ['contact_id' => $bob->id, 'relationship_id' => $agent->id],
        ]);

        $listed = collect($this->getJson('/api/v1/relationships')->assertOk()->json('data'))->keyBy('label');
        $this->assertSame(1, $listed['Owner']['links']);
        $this->assertSame(1, $listed['Owner']['objects']);

        $this->deleteJson("/api/v1/relationships/{$owner->id}")->assertNoContent();

        $this->assertDatabaseMissing('data_object_relationships', ['id' => $owner->id]);
        $this->assertSame([$bob->id], $record->contacts()->pluck('contacts.id')->all());              // Ann is disconnected, Bob keeps his link
        $this->assertTrue(Contact::whereKey($ann->id)->exists() && $record->fresh()->exists);          // the contact and the item stay
        $this->assertSame(['Agent'], $object->relationships()->pluck('label')->all());
    }
}
