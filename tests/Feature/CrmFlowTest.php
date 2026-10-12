<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAutomation;
use App\Jobs\RunAutomationActions;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRelationship;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Automation\ActionRunner;
use App\Services\DataObjects\RecordQuery;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function dealsObject(): array
    {
        $rel = DataObjectRelationship::create(['label' => 'Owner']);
        $object = DataObject::create(['name' => 'Deals']);
        $object->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'is_required' => true],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['choices' => ['New', 'Won']], 'is_indexed' => true],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'is_indexed' => true],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text'],   // NOT indexed -> JSON path fallback
        ]);
        $object->relationships()->attach($rel->id);

        return [$object->fresh('fields'), $rel];
    }

    public function test_contact_crud_search_and_tags(): void
    {
        $this->postJson('/api/v1/contacts', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'tags' => ['vip']])
            ->assertCreated()->assertJsonPath('data.tags.0', 'vip');

        $this->postJson('/api/v1/contacts', ['first_name' => 'Dup', 'email' => 'ada@example.com'])->assertUnprocessable();

        $id = Contact::first()->id;
        $this->patchJson("/api/v1/contacts/$id", ['company_name' => 'Analytical'])->assertOk()->assertJsonPath('data.company_name', 'Analytical');
        $this->getJson('/api/v1/contacts?filter[q]=Ada')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts?filter[tag]=vip')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/contacts/$id/timeline")->assertOk()->assertJsonPath('data.0.type', 'contact.updated');
        $this->deleteJson("/api/v1/contacts/$id")->assertNoContent();
        $this->assertSoftDeleted('contacts', ['id' => $id]);
    }

    public function test_suspended_users_and_unprivileged_users_are_blocked(): void
    {
        $this->postJson('/api/v1/data-objects', ['name' => 'X'])->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        $this->postJson('/api/v1/data-objects', ['name' => 'Y'])->assertForbidden();
        $this->getJson('/api/v1/contacts')->assertForbidden();   // no permission template

        Sanctum::actingAs(User::factory()->create(['status' => 'suspended']));
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_record_validation_indexing_and_filtering(): void
    {
        [$object, $rel] = $this->dealsObject();
        $service = app(RecordService::class);

        $this->postJson("/api/v1/data-objects/{$object->id}/records", ['data' => ['stage' => 'New']])->assertUnprocessable();
        $this->postJson("/api/v1/data-objects/{$object->id}/records", ['data' => ['title' => 'T', 'stage' => 'Bogus']])->assertUnprocessable();

        $service->create($object, ['title' => 'Small', 'stage' => 'New', 'amount' => 500, 'region' => 'EU']);
        $service->create($object, ['title' => 'Big', 'stage' => 'Won', 'amount' => 5000, 'region' => 'US']);
        $service->create($object, ['title' => 'Huge', 'stage' => 'Won', 'amount' => 90000, 'region' => 'EU']);

        $this->assertDatabaseCount('data_object_field_index', 6);   // stage + amount per record only

        $ids = fn (array $filter) => (new RecordQuery($object))->build($filter)->pluck('data_object_records.id')->all();

        $this->assertCount(2, $ids(['rules' => [['field' => 'stage', 'op' => '=', 'value' => 'Won']]]));
        $this->assertCount(1, $ids(['rules' => [['field' => 'amount', 'op' => '>', 'value' => 10000]]]));
        $this->assertCount(1, $ids(['rules' => [['field' => 'stage', 'op' => '=', 'value' => 'Won'], ['field' => 'region', 'op' => '=', 'value' => 'EU']]]));
        $this->assertCount(3, $ids(['match' => 'any', 'rules' => [['field' => 'region', 'op' => '=', 'value' => 'EU'], ['field' => 'stage', 'op' => '=', 'value' => 'Won']]]));

        $this->getJson("/api/v1/data-objects/{$object->id}/records?q=Huge")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/data-objects/{$object->id}/records?filter[rules][0][field]=nope&filter[rules][0][op]==&filter[rules][0][value]=1")->assertUnprocessable();
    }

    public function test_exclusive_relationship_is_enforced(): void
    {
        [$object, $rel] = $this->dealsObject();
        $object->relationships()->updateExistingPivot($rel->id, ['is_exclusive' => true]);
        $record = app(RecordService::class)->create($object->fresh('fields'), ['title' => 'T']);
        [$a, $b] = [Contact::create(['first_name' => 'A', 'email' => 'a@x.io']), Contact::create(['first_name' => 'B', 'email' => 'b@x.io'])];

        $url = "/api/v1/data-objects/{$object->id}/records/{$record->id}/contacts";
        $this->postJson($url, ['contact_id' => $a->id, 'relationship_id' => $rel->id])->assertOk();
        $this->postJson($url, ['contact_id' => $b->id, 'relationship_id' => $rel->id])->assertUnprocessable();
    }

    public function test_automation_fires_once_and_queues_email(): void
    {
        [$object, $rel] = $this->dealsObject();
        $contact = Contact::create(['first_name' => 'Ada', 'email' => 'ada@example.com']);
        $automation = Automation::create([
            'name' => 'Won deal follow-up', 'data_object_id' => $object->id, 'schedule' => 'every_minute',
            'conditions' => ['rules' => [['field' => 'stage', 'op' => '=', 'value' => 'Won']]],
            'actions' => [['type' => 'send_email', 'subject' => 'Congrats {{contact.first_name}}!', 'html' => '<p>Deal {{record.title}} won</p>'],
                ['type' => 'add_tag', 'tag' => 'customer']],
        ]);
        $service = app(RecordService::class);
        $service->create($object, ['title' => 'Lost', 'stage' => 'New']);
        $service->create($object, ['title' => 'Alpha', 'stage' => 'Won'], null, [['contact_id' => $contact->id, 'relationship_id' => $rel->id]]);

        Queue::fake([RunAutomationActions::class]);
        app()->call([new EvaluateAutomation($automation->id), 'handle']);
        Queue::assertPushed(RunAutomationActions::class, 1);

        app()->call([new EvaluateAutomation($automation->id), 'handle']);           // second pass must not re-fire
        Queue::assertPushed(RunAutomationActions::class, 1);

        $run = $automation->runs()->first();
        (new RunAutomationActions($run->id))->handle(app(ActionRunner::class));

        $this->assertSame('done', $run->fresh()->status);
        $email = EmailMessage::first();
        $this->assertSame('Congrats Ada!', $email->subject);
        $this->assertStringContainsString('Deal Alpha won', $email->html);
        $this->assertTrue($contact->tags()->where('name', 'customer')->exists());
    }

    public function test_opted_out_contacts_get_no_email_and_tracking_pixel_counts_opens(): void
    {
        $contact = Contact::create(['first_name' => 'No', 'email' => 'no@x.io', 'email_opt_out' => true]);
        $this->postJson("/api/v1/contacts/{$contact->id}/email", ['subject' => 'Hi', 'html' => '<p>x</p>'])->assertUnprocessable();

        $ok = Contact::create(['first_name' => 'Yes', 'email' => 'yes@x.io']);
        Queue::fake();
        $this->postJson("/api/v1/contacts/{$ok->id}/email", ['subject' => 'Hi', 'html' => '<p>x</p>'])->assertAccepted();
        $msg = EmailMessage::first();

        $this->get("/t/o/{$msg->tracker_id}")->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertSame(1, $msg->fresh()->open_count);
    }

    public function test_merge_contacts_repoints_related_rows(): void
    {
        $keep = Contact::create(['first_name' => 'Ada', 'email' => 'a1@x.io']);
        $dup = Contact::create(['first_name' => 'Ada', 'phone' => '555', 'email' => 'a2@x.io']);
        $dup->notes()->create(['body' => 'hello']);

        $this->postJson("/api/v1/contacts/{$keep->id}/merge", ['duplicate_id' => $dup->id])->assertOk()->assertJsonPath('data.phone', '555');
        $this->assertSame(1, $keep->notes()->count());
        $this->assertSoftDeleted('contacts', ['id' => $dup->id]);
    }

    public function test_dashboard_survives_a_cache_hit(): void
    {
        config(['cache.default' => 'database']);   // real serializing store, like production
        $this->dealsObject();
        Cache::flush();

        $first = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.objects');
        $second = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.objects');   // served from cache

        $this->assertSame('Deals', $second[0]['name']);
        $this->assertSame($first, $second);
    }

    public function test_schema_editor_adds_fields_and_reindexes_existing_records(): void
    {
        $object = DataObject::create(['name' => 'Props']);
        $object->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'text']);
        $object->fields()->create(['key' => 'price', 'label' => 'Price', 'type' => 'number', 'is_indexed' => false]);
        $object = $object->fresh('fields');
        $service = app(RecordService::class);
        $service->create($object, ['title' => 'A', 'price' => 100]);
        $service->create($object, ['title' => 'B', 'price' => 900]);
        $this->assertDatabaseCount('data_object_field_index', 0);

        $field = fn (string $key, string $label, string $type, array $extra = []) => ['key' => $key, 'label' => $label, 'type' => $type] + $extra;

        // turn indexing ON for price, add a select with choices; existing records must be back-filled
        $this->patchJson("/api/v1/data-objects/{$object->id}", ['fields' => [
            $field('title', 'Title', 'text', ['is_required' => true]),
            $field('price', 'Price', 'number', ['is_indexed' => true]),
            $field('status', 'Status', 'select', ['options' => ['choices' => ['Open', 'Sold']], 'show_in_list' => true]),
        ]])->assertOk()->assertJsonCount(3, 'data.fields');

        $this->assertDatabaseCount('data_object_field_index', 2);
        $fresh = $object->fresh('fields');
        $this->assertCount(1, (new RecordQuery($fresh))->build(['rules' => [['field' => 'price', 'op' => '>', 'value' => 500]]])->get());

        // new select field is enforced on write
        $this->postJson("/api/v1/data-objects/{$object->id}/records", ['data' => ['title' => 'C', 'status' => 'Nope']])->assertUnprocessable();
        $this->postJson("/api/v1/data-objects/{$object->id}/records", ['data' => ['title' => 'C', 'status' => 'Sold']])->assertCreated();

        // removing a field drops its index rows but keeps the JSON values
        $this->patchJson("/api/v1/data-objects/{$object->id}", ['fields' => [$field('title', 'Title', 'text')]])->assertOk()->assertJsonCount(1, 'data.fields');
        $this->assertDatabaseCount('data_object_field_index', 0);
        $this->assertSame(100, (int) $object->records()->first()->data['price']);

        // bad key is rejected
        $this->patchJson("/api/v1/data-objects/{$object->id}", ['fields' => [$field('Bad Key', 'X', 'text')]])->assertUnprocessable();
    }

    public function test_relationship_types_can_be_listed_and_created_by_admins_only(): void
    {
        $this->postJson('/api/v1/relationships', ['label' => 'Buyer'])->assertCreated();
        $this->postJson('/api/v1/relationships', ['label' => 'Buyer'])->assertUnprocessable();
        $this->getJson('/api/v1/relationships')->assertOk()->assertJsonPath('data.0.label', 'Buyer');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/relationships', ['label' => 'Other'])->assertForbidden();
    }

    public function test_merge_keeps_json_fields_intact_and_honours_transfer_options(): void
    {
        $keep = Contact::create(['first_name' => 'Ada', 'email' => 'k@x.io']);
        $dup = Contact::create(['first_name' => 'Ada', 'email' => 'd@x.io', 'addresses' => [['city' => 'Austin']], 'custom_fields' => ['plan' => 'gold']]);
        $dup->notes()->create(['body' => 'stays with duplicate']);

        $this->postJson("/api/v1/contacts/{$keep->id}/merge", ['duplicate_id' => $dup->id, 'notes_tasks' => 'none'])->assertOk()
            ->assertJsonPath('data.addresses.0.city', 'Austin')->assertJsonPath('data.custom_fields.plan', 'gold');

        $this->assertSame(0, $keep->notes()->count());                         // notes_tasks=none respected
        $this->assertSame('Austin', $keep->fresh()->addresses[0]['city']);      // really an array, not a JSON string
    }

    public function test_duplicate_check_matches_email_phone_and_name(): void
    {
        Contact::create(['first_name' => 'Bob', 'last_name' => 'Ray', 'email' => 'bob@x.io', 'phone' => '555-123-4567']);

        $this->getJson('/api/v1/contacts/duplicates?email=bob@x.io')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts/duplicates?phone=555-123-4567')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts/duplicates?first_name=Bob&last_name=Ray')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts/duplicates?first_name=Bob')->assertOk()->assertJsonCount(0, 'data');   // name alone is not enough
        $this->getJson('/api/v1/contacts/duplicates')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_every_contact_field_the_form_sends_is_persisted(): void
    {
        $payload = [
            'first_name' => 'Grace', 'middle_name' => 'B', 'last_name' => 'Murray', 'title' => 'Dr', 'job_title' => 'Eng', 'company_name' => 'Navy',
            'website' => 'https://navy.example.com', 'birthday' => '1906-12-09', 'email' => 'grace@x.io', 'email_secondary' => 'g2@x.io',
            'phone' => '+1 555 1', 'phone_secondary' => '+1 555 2', 'contact_type' => 'Customer', 'timezone' => 'America/New_York', 'notes' => 'n',
            'email_opt_out' => true, 'owner_id' => $this->admin->id, 'emails' => [['email' => 'a@x.io', 'type' => 'Work']],
            'phones' => [['number' => '+1 555 3', 'ext' => '9', 'type' => 'Mobile']], 'addresses' => [['type' => 'BILLING', 'street1' => '1 Main', 'city' => 'Arlington', 'country' => 'US']],
            'custom_fields' => ['plan' => 'gold'], 'tags' => ['vip'],
        ];
        $id = $this->postJson('/api/v1/contacts', $payload)->assertCreated()->json('data.id');
        $stored = $this->getJson("/api/v1/contacts/$id")->json('data');
        foreach (['first_name', 'middle_name', 'last_name', 'title', 'job_title', 'company_name', 'website', 'birthday', 'email', 'email_secondary', 'phone', 'phone_secondary',
            'contact_type', 'timezone', 'notes', 'email_opt_out', 'emails', 'phones', 'custom_fields'] as $k) {
            $this->assertEquals($payload[$k], $stored[$k], "contact field [$k] was not persisted");
        }
        $this->assertSame('1 Main', $stored['addresses'][0]['street1']);
        $this->assertSame($this->admin->id, $stored['owner']['id']);
        $this->assertSame(['vip'], $stored['tags']);
    }
}
