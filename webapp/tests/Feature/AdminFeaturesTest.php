<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\DataObjectRelationship;
use App\Models\DataTransfer;
use App\Models\EmailMessage;
use App\Models\Form;
use App\Models\PriceList;
use App\Models\Task;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DataObject $deals;

    private DataObjectRelationship $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
        Storage::fake('local');

        $this->owner = DataObjectRelationship::create(['label' => 'Owner']);
        $this->deals = DataObject::create(['name' => 'Deals']);
        $this->deals->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'is_required' => true, 'show_in_list' => true, 'position' => 0],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['choices' => ['New', 'Won', 'Lost']], 'is_indexed' => true, 'position' => 1],
            ['key' => 'visit', 'label' => 'Visit', 'type' => 'date', 'is_indexed' => true, 'position' => 2],
            ['key' => 'note', 'label' => 'Note', 'type' => 'text', 'position' => 3],
        ]);
        $this->deals->relationships()->attach($this->owner->id);
        $this->deals = $this->deals->fresh('fields');
    }

    private function csv(string $content): string
    {
        $f = UploadedFile::fake()->createWithContent('data.csv', $content);

        return $this->post('/api/v1/uploads', ['file' => $f], ['Accept' => 'application/json'])->assertCreated()->json('path');
    }

    public function test_a_board_shows_its_columns_in_the_order_they_were_arranged(): void
    {
        app(RecordService::class)->create($this->deals, ['title' => 'C']);          // no stage: the "No value" column appears, after the others
        $id = $this->postJson('/api/v1/boards', ['name' => 'Pipeline', 'data_object_id' => $this->deals->id, 'key_field' => 'stage', 'column_order' => ['Lost', 'Won']])->assertCreated()->json('data.id');

        // the choices are New, Won, Lost: the arranged ones first, one that was not mentioned (New) after them, "No value" last
        $this->assertSame(['Lost', 'Won', 'New', ''], collect($this->getJson("/api/v1/boards/$id")->json('columns'))->pluck('key')->all());

        $this->putJson("/api/v1/boards/$id", ['name' => 'Pipeline', 'data_object_id' => $this->deals->id, 'key_field' => 'stage', 'column_order' => ['Won', 'Gone', 'New', 'Lost']])->assertOk();
        $this->assertSame(['Won', 'New', 'Lost', ''], collect($this->getJson("/api/v1/boards/$id")->json('columns'))->pluck('key')->all());     // a choice that is not in the field any more is ignored

        $this->putJson("/api/v1/boards/$id", ['name' => 'Pipeline', 'data_object_id' => $this->deals->id, 'key_field' => 'stage', 'column_order' => null])->assertOk();
        $this->assertSame(['New', 'Won', 'Lost', ''], collect($this->getJson("/api/v1/boards/$id")->json('columns'))->pluck('key')->all());      // no arrangement: the order of the field
    }

    public function test_workflow_board_columns_cards_and_moving(): void
    {
        $svc = app(RecordService::class);
        $c = Contact::create(['first_name' => 'Ann', 'email' => 'a@x.io']);
        Task::create(['contact_id' => $c->id, 'title' => 'Call', 'assigned_to' => $this->admin->id]);
        $a = $svc->create($this->deals, ['title' => 'A', 'stage' => 'New'], null, [['contact_id' => $c->id, 'relationship_id' => $this->owner->id]]);
        $svc->create($this->deals, ['title' => 'B', 'stage' => 'Won']);
        $svc->create($this->deals, ['title' => 'C']);   // no stage

        $this->postJson('/api/v1/boards', ['name' => 'Pipeline', 'data_object_id' => $this->deals->id, 'key_field' => 'title'])->assertUnprocessable();   // key must be a dropdown
        $id = $this->postJson('/api/v1/boards', ['name' => 'Pipeline', 'data_object_id' => $this->deals->id, 'key_field' => 'stage', 'relationship_id' => $this->owner->id,
            'colors' => ['New' => '#3b82f6']])->assertCreated()->json('data.id');

        $res = $this->getJson("/api/v1/boards/$id")->assertOk();
        $cols = collect($res->json('columns'))->keyBy('key');
        $this->assertSame(['New', 'Won', 'Lost', ''], $cols->keys()->all());
        $this->assertSame(1, $cols['New']['total']);
        $this->assertSame('#3b82f6', $cols['New']['color']);
        $this->assertTrue($cols['New']['cards'][0]['contacts'][0]['has_tasks']);          // outstanding-task flag
        $this->assertSame('No value', $cols['']['label']);

        $this->patchJson("/api/v1/boards/$id/cards/{$a->id}", ['to' => 'Won'])->assertOk();
        $this->assertSame('Won', $a->fresh()->data['stage']);
        $this->patchJson("/api/v1/boards/$id/cards/{$a->id}", ['to' => 'Bogus'])->assertUnprocessable();   // still validated against the field

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/boards', ['name' => 'x', 'data_object_id' => $this->deals->id, 'key_field' => 'stage'])->assertForbidden();
    }

    public function test_calendar_merges_tasks_and_scheduled_items_and_validates_schedules(): void
    {
        $this->postJson('/api/v1/schedules', ['name' => 'Visits', 'data_object_id' => $this->deals->id, 'start_field' => 'title', 'type' => 'date'])->assertUnprocessable();   // not a date field
        $this->postJson('/api/v1/schedules', ['name' => 'Visits', 'data_object_id' => $this->deals->id, 'start_field' => 'visit', 'type' => 'date'])->assertCreated();

        $svc = app(RecordService::class);
        $svc->create($this->deals, ['title' => 'Site visit', 'visit' => '2026-11-10']);
        $svc->create($this->deals, ['title' => 'Later', 'visit' => '2027-01-10']);
        Task::create(['title' => 'Prep', 'assigned_to' => $this->admin->id, 'due_at' => '2026-11-05 09:00:00']);
        Task::create(['title' => 'Not mine', 'assigned_to' => User::factory()->create()->id, 'due_at' => '2026-11-06 09:00:00']);

        $ev = $this->getJson('/api/v1/calendar?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(['Prep', 'Site visit'], array_column($ev, 'title'));        // sorted by time, other user's task and out-of-range item excluded
        $this->assertSame(['task', 'item'], array_column($ev, 'kind'));
        $this->getJson('/api/v1/calendar?from=2026-01-01&to=2026-12-31')->assertUnprocessable();   // range cap
    }

    public function test_form_builder_public_submission_creates_contact_and_record_and_blocks_bots(): void
    {
        $schema = ['columns' => 2, 'fields' => [
            ['key' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['key' => 'title', 'label' => 'Deal title', 'type' => 'text', 'map_to' => 'record.title'],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['New', 'Won'], 'map_to' => 'record.stage'],
        ]];
        $this->postJson('/api/v1/forms', ['name' => 'Bad', 'schema' => ['fields' => [['key' => 'x', 'label' => 'X', 'type' => 'text', 'map_to' => 'contact.password']]]])->assertUnprocessable();
        $id = $this->postJson('/api/v1/forms', ['name' => 'Lead', 'data_object_id' => $this->deals->id, 'schema' => $schema,
            'settings' => ['relationship_id' => $this->owner->id, 'thank_you' => 'Thanks!', 'notify_emails' => ['sales@x.io']]])->assertCreated()->json('data.id');
        $form = Form::find($id);

        $this->getJson('/api/v1/forms')->assertOk()->assertJsonPath('data.0.public_url', url("/f/{$form->uuid}"));
        $this->get("/f/{$form->uuid}")->assertOk()->assertSee('Deal title')->assertSee('name="_hp"', false);
        $this->get("/f/{$form->uuid}.js")->assertOk()->assertHeader('Content-Type', 'application/javascript');

        // anonymous visitor submits
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ThrottleRequests::class);
        $url = "/api/v1/forms/{$form->uuid}/submit";
        $this->postJson($url, ['email' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'email']);
        $this->postJson($url, ['first_name' => 'Zed', 'email' => 'Zed@X.io', 'title' => 'Big', 'stage' => 'Bogus'])->assertUnprocessable()->assertJsonValidationErrors('stage');

        $this->postJson($url, ['first_name' => 'Zed', 'email' => 'Zed@X.io', 'title' => 'Big', 'stage' => 'New'])->assertCreated()->assertJsonPath('thank_you', 'Thanks!');
        $contact = Contact::where('email', 'zed@x.io')->firstOrFail();
        $this->assertSame('form:'.$id, $contact->source);
        $record = DataObjectRecord::firstOrFail();
        $this->assertSame(['title' => 'Big', 'stage' => 'New'], $record->data);
        $this->assertTrue($record->contacts->contains($contact));
        $this->assertSame(1, EmailMessage::where('subject', 'like', 'New submission%')->count());     // notify e-mail queued (sync queue)

        $before = Contact::count();
        $this->postJson($url, ['first_name' => 'Bot', 'email' => 'bot@x.io', '_hp' => 'gotcha'])->assertCreated();
        $this->assertSame($before, Contact::count());                                                 // honeypot: silently dropped
    }

    public function test_contact_import_maps_columns_matches_by_email_and_reports_row_errors(): void
    {
        Contact::create(['first_name' => 'Old', 'last_name' => 'Name', 'email' => 'ann@x.io', 'phone' => '999']);
        $path = $this->csv("First Name,Email Address,Phone,Tags\nAnn,ann@x.io,,\"vip, lead\"\nBob,bob@x.io,555,\nBroken,not-an-email,1,\n");

        $p = $this->postJson('/api/v1/transfers/preview', ['path' => $path, 'resource' => 'contacts'])->assertOk();
        $this->assertSame(['First Name', 'Email Address', 'Phone', 'Tags'], $p->json('headers'));
        $this->assertSame(3, $p->json('total_rows'));
        $this->assertSame('first_name', $p->json('mapping')['First Name']);     // auto-guess

        $mapping = ['First Name' => 'first_name', 'Email Address' => 'email', 'Phone' => 'phone', 'Tags' => 'tags'];
        $this->postJson('/api/v1/transfers', ['direction' => 'import', 'resource' => 'contacts', 'path' => $path, 'options' => ['mapping' => ['Phone' => 'phone']]])->assertUnprocessable();   // email required
        $this->postJson('/api/v1/transfers', ['direction' => 'import', 'resource' => 'contacts', 'path' => 'tmp/999/x.csv', 'options' => ['mapping' => $mapping]])->assertUnprocessable();   // not own file
        $this->postJson('/api/v1/transfers', ['direction' => 'import', 'resource' => 'contacts', 'path' => $path, 'options' => ['mapping' => $mapping]])->assertStatus(202);

        $t = DataTransfer::latest('id')->first();
        $this->assertSame('done', $t->status);
        $this->assertSame([2, 1], [$t->processed, $t->failed]);
        $this->assertStringContainsString('Row 4', $t->error);
        $ann = Contact::where('email', 'ann@x.io')->first();
        $this->assertSame('999', $ann->phone);                                  // empty CSV cell did NOT blank the existing phone
        $this->assertSame('Ann', $ann->first_name);                             // provided cell updated
        $this->assertEqualsCanonicalizing(['vip', 'lead'], $ann->tags()->pluck('name')->all());
        $this->assertSame(2, Contact::count() - 0 - Contact::whereNull('email')->count());
    }

    public function test_data_object_import_upserts_by_identifying_field_and_links_contacts(): void
    {
        $c = Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
        app(RecordService::class)->create($this->deals, ['title' => 'Existing', 'stage' => 'New']);
        $path = $this->csv("Title,Stage,Contact\nExisting,Won,ann@x.io\nBrand new,New,ann@x.io\nBad stage,Zzz,\nGhost,New,nobody@x.io\n");
        $mapping = ['Title' => 'title', 'Stage' => 'stage', 'Contact' => '_contact_email'];

        $this->postJson('/api/v1/transfers', ['direction' => 'import', 'resource' => 'data_objects', 'path' => $path, 'options' => ['data_object_id' => $this->deals->id, 'mapping' => $mapping]])->assertUnprocessable();   // needs relationship
        $this->postJson('/api/v1/transfers', ['direction' => 'import', 'resource' => 'data_objects', 'path' => $path,
            'options' => ['data_object_id' => $this->deals->id, 'identifying_field' => 'title', 'relationship_id' => $this->owner->id, 'mapping' => $mapping]])->assertStatus(202);

        $t = DataTransfer::latest('id')->first();
        $this->assertSame([2, 2], [$t->processed, $t->failed]);                 // Bad stage + unknown contact fail
        $this->assertSame(2, DataObjectRecord::count());                         // "Existing" updated in place, "Brand new" created
        $this->assertSame('Won', DataObjectRecord::where('data->title', 'Existing')->first()->data['stage']);
        $this->assertSame(2, $c->dataObjectRecords()->count());
    }

    public function test_exports_are_chunked_csv_with_formula_guard_and_admin_only_download(): void
    {
        Contact::create(['first_name' => '=HYPERLINK("x")', 'last_name' => 'Evil', 'email' => 'evil@x.io']);
        Contact::create(['first_name' => 'Ok', 'last_name' => 'Person', 'email' => 'ok@x.io']);

        $id = $this->postJson('/api/v1/transfers', ['direction' => 'export', 'resource' => 'contacts', 'options' => []])->assertStatus(202)->json('data.id');
        $t = DataTransfer::find($id);
        $this->assertSame(['done', 2], [$t->status, $t->processed]);
        $csv = $this->get("/api/v1/transfers/$id/download")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('ok@x.io', $csv);

        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/v1/transfers/$id/download")->assertNotFound();
        $this->getJson('/api/v1/transfers')->assertForbidden();
    }

    public function test_price_lists_lock_share_and_formula_safety(): void
    {
        $data = ['currency' => 'USD', 'categories' => [['name' => 'Services', 'items' => [['name' => 'Consulting', 'unit' => 'hour', 'price' => 150], ['name' => 'Area', 'formula' => 'width*height']]]]];
        $this->postJson('/api/v1/price-lists', ['title' => 'Rates', 'form_type' => 'standard', 'data' => array_replace_recursive($data, ['categories' => [['items' => [1 => ['formula' => 'system("rm -rf")']]]]])])->assertUnprocessable();   // code-ish formula rejected
        $id = $this->postJson('/api/v1/price-lists', ['title' => 'Rates', 'form_type' => 'standard', 'data' => $data])->assertCreated()->json('data.id');
        $list = PriceList::find($id);

        $this->get("/p/{$list->token}")->assertNotFound();                      // not shared until locked
        $this->postJson("/api/v1/price-lists/$id/lock")->assertOk();
        $this->get("/p/{$list->token}")->assertOk()->assertSee('Consulting')->assertSee('150.00 USD');
        $this->putJson("/api/v1/price-lists/$id", ['title' => 'x', 'form_type' => 'standard', 'data' => $data])->assertStatus(423);        // locked = read only
        $this->postJson("/api/v1/price-lists/$id/duplicate")->assertCreated()->assertJsonPath('data.title', 'Rates (copy)');
    }

    public function test_tours_stops_and_notifications_with_visit_date(): void
    {
        $a = Contact::create(['first_name' => 'Ann', 'email' => 'a@x.io', 'addresses' => [['city' => 'Austin']]]);
        $b = Contact::create(['first_name' => 'Bo', 'email' => 'b@x.io', 'email_opt_out' => true]);
        $id = $this->postJson('/api/v1/tours', ['title' => 'Texas', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-05', 'stops' => [
            ['contact_id' => $a->id, 'visit_at' => '2026-11-02 10:00:00'], ['contact_id' => $b->id],
        ]])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/tours', ['title' => 'Bad', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-01'])->assertUnprocessable();

        $show = $this->getJson("/api/v1/tours/$id")->assertOk();
        $this->assertSame('Austin', $show->json('data.stops.0.address.city'));         // address defaults from the contact

        $this->postJson("/api/v1/tours/$id/notify", ['subject' => 'Visit on {{visit_date}}', 'html' => '<p>Hi {{contact.first_name}}, see you {{visit_date}}</p>'])->assertStatus(202)->assertJsonPath('queued', 1);
        $m = EmailMessage::latest('id')->first();
        $this->assertSame('Visit on Nov 2, 2026 10:00am', $m->subject);
        $this->assertStringContainsString('Hi Ann, see you Nov 2, 2026 10:00am', $m->html);
        $this->assertSame(1, EmailMessage::count());                                    // opted-out Bo skipped
    }
}
