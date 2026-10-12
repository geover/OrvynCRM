<?php

namespace Tests\Feature;

use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRelationship;
use App\Models\EmailMessage;
use App\Models\Tag;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueriesAndWidgetsTest extends TestCase
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

        $this->owner = DataObjectRelationship::create(['label' => 'Owner']);
        $this->deals = DataObject::create(['name' => 'Deals']);
        $this->deals->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'show_in_list' => true],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['choices' => ['New', 'Won']], 'is_indexed' => true, 'show_in_list' => true],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'is_indexed' => true],
        ]);
        $this->deals = $this->deals->fresh('fields');

        $svc = app(RecordService::class);
        $vip = Tag::create(['name' => 'vip']);
        foreach ([['Ann', 'ann@x.io', true, 'Won', 9000, '=cmd|calc'], ['Bob', 'bob@x.io', false, 'Won', 100, 'ok'], ['Cy', 'cy@x.io', true, 'New', 5000, 'ok']] as [$name, $email, $isVip, $stage, $amount, $title]) {
            $c = Contact::create(['first_name' => $name, 'email' => $email]);
            $isVip && $c->tags()->attach($vip);
            $svc->create($this->deals, ['title' => $title, 'stage' => $stage, 'amount' => $amount], null, [['contact_id' => $c->id, 'relationship_id' => $this->owner->id]]);
        }
    }

    private function def(array $extra = []): array
    {
        return ['scope' => 'data_object', 'definition' => ['data_object_id' => $this->deals->id] + $extra];
    }

    public function test_builder_meta_lists_contact_and_object_fields(): void
    {
        $this->getJson('/api/v1/queries/meta')->assertOk()->assertJsonPath('objects.0.name', 'Deals')->assertJsonPath('objects.0.fields.1.key', 'stage')
            ->assertJsonFragment(['key' => 'tag']);
    }

    public function test_preview_filters_records_and_by_linked_contact_attributes(): void
    {
        $won = ['filter' => ['rules' => [['field' => 'stage', 'op' => '=', 'value' => 'Won']]]];
        $this->postJson('/api/v1/queries/preview', $this->def($won))->assertOk()->assertJsonPath('total', 2)->assertJsonPath('columns.0.key', 'title');

        // won deals whose owner contact carries the "vip" tag -> only Ann
        $vipWon = $won + ['contact_filter' => ['rules' => [['field' => 'tag', 'op' => '=', 'value' => ['vip']]]], 'relationship_ids' => [$this->owner->id]];
        $res = $this->postJson('/api/v1/queries/preview', $this->def($vipWon))->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('Ann', explode(' ', $res->json('rows.0.contacts.0.name'))[0]);

        // OR group + custom columns
        $any = ['match' => 'any', 'rules' => [['field' => 'amount', 'op' => '>', 'value' => 8000], ['field' => 'stage', 'op' => '=', 'value' => 'New']]];
        $this->postJson('/api/v1/queries/preview', $this->def(['filter' => $any, 'columns' => ['amount']]))->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'columns');
    }

    public function test_contact_scope_queries_and_validation(): void
    {
        $res = $this->postJson('/api/v1/queries/preview', ['scope' => 'contact', 'definition' => ['contact_filter' => ['rules' => [['field' => 'tag', 'op' => '=', 'value' => ['vip']]]]]])->assertOk();
        $this->assertSame(2, $res->json('total'));

        $this->postJson('/api/v1/queries/preview', ['scope' => 'contact', 'definition' => ['contact_filter' => ['rules' => [['field' => 'password', 'op' => '=', 'value' => 'x']]]]])->assertUnprocessable();   // not whitelisted
        $this->postJson('/api/v1/queries/preview', ['scope' => 'contact', 'definition' => ['contact_filter' => ['rules' => [['field' => 'email', 'op' => 'DROP', 'value' => 'x']]]]])->assertUnprocessable();
    }

    public function test_saved_query_widget_data_export_and_formula_injection_guard(): void
    {
        $qid = $this->postJson('/api/v1/queries', ['name' => 'Won deals'] + $this->def(['filter' => ['rules' => [['field' => 'stage', 'op' => '=', 'value' => 'Won']]]]) + ['data_object_id' => $this->deals->id])
            ->assertCreated()->json('data.id');
        $this->getJson("/api/v1/queries/$qid/run")->assertOk()->assertJsonPath('total', 2);

        $wid = $this->postJson('/api/v1/widgets', ['name' => 'Won', 'saved_query_id' => $qid, 'shared' => true])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/widgets/$wid/data?limit=1")->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'rows')->assertJsonPath('query.name', 'Won deals');

        $csv = $this->get("/api/v1/widgets/$wid/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('Title,Stage', $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv);        // leading = is neutralised
        $this->assertStringNotContainsString(',=cmd', $csv);

        // another user sees shared widgets, but cannot delete them
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/widgets')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/widgets/$wid")->assertForbidden();
    }

    public function test_batch_email_queues_one_message_per_eligible_contact(): void
    {
        Contact::where('email', 'cy@x.io')->update(['email_opt_out' => true]);
        $qid = $this->postJson('/api/v1/queries', ['name' => 'VIP', 'scope' => 'contact', 'definition' => ['contact_filter' => ['rules' => [['field' => 'tag', 'op' => '=', 'value' => ['vip']]]]]])->assertCreated()->json('data.id');
        $wid = $this->postJson('/api/v1/widgets', ['name' => 'VIPs', 'saved_query_id' => $qid])->assertCreated()->json('data.id');

        Bus::fake([SendEmailMessage::class]);
        $this->postJson("/api/v1/widgets/$wid/batch/email", ['subject' => 'Hello {{contact.first_name}}', 'html' => '<p>Hi</p>'])->assertAccepted();

        $this->assertSame(1, EmailMessage::count());                    // Ann only: Cy opted out, Bob is not tagged
        $this->assertSame('Hello Ann', EmailMessage::first()->subject);
        $this->assertSame($this->admin->id, EmailMessage::first()->user_id);
    }
}
