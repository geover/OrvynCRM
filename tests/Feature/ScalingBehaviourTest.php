<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DataObject;
use App\Models\User;
use App\Models\WorkflowBoard;
use App\Services\DataObjects\RecordQuery;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Behaviour the scaling work must not change (found with bench:run): board totals, empty filters, index hygiene. */
class ScalingBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private DataObject $deals;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deals = DataObject::create(['name' => 'Deals']);
        $this->deals->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'show_in_list' => true, 'position' => 0],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['choices' => ['New', 'Won', 'Lost']], 'is_indexed' => true, 'position' => 1],
        ]);
        $this->deals = $this->deals->fresh('fields');
    }

    private function deal(string $title, ?string $stage)
    {
        return app(RecordService::class)->create($this->deals, array_filter(['title' => $title, 'stage' => $stage], fn ($v) => $v !== null));
    }

    public function test_board_totals_come_from_the_index_and_ignore_deleted_records(): void
    {
        $this->deal('a', 'New');
        $this->deal('b', 'New');
        $this->deal('c', 'Won');
        $gone = $this->deal('d', 'Won');
        $this->deal('e', null);                                                       // no stage at all
        $board = WorkflowBoard::create(['name' => 'Pipeline', 'data_object_id' => $this->deals->id, 'key_field' => 'stage', 'status' => 'active']);

        $this->deleteJson("/api/v1/data-objects/{$this->deals->id}/records/{$gone->id}")->assertNoContent();

        $columns = collect($this->getJson("/api/v1/boards/{$board->id}")->assertOk()->json('columns'))->keyBy('key');
        $this->assertSame(2, $columns['New']['total']);
        $this->assertSame(1, $columns['Won']['total']);
        $this->assertCount(1, $columns['Won']['cards']);
        $this->assertArrayNotHasKey('Lost', $columns->filter(fn ($c) => $c['total'] > 0)->all());
        $this->assertSame(1, $columns['']['total']);                                    // "No value" = records without a stage
        $this->assertSame('e', $columns['']['cards'][0]['values']['title'] ?? null);
    }

    public function test_deleting_a_record_removes_its_index_rows(): void
    {
        $keep = $this->deal('keep', 'New');
        $gone = $this->deal('gone', 'New');

        $this->deleteJson("/api/v1/data-objects/{$this->deals->id}/records/{$gone->id}")->assertNoContent();

        $this->assertSame(0, DB::table('data_object_field_index')->where('record_id', $gone->id)->count());
        $this->assertSame(1, DB::table('data_object_field_index')->where('record_id', $keep->id)->count());
    }

    public function test_empty_and_not_empty_filters_on_an_indexed_field(): void
    {
        $this->deal('has', 'Won');
        $this->deal('hasnt', null);

        $titles = fn (string $op) => (new RecordQuery($this->deals))->build(['rules' => [['field' => 'stage', 'op' => $op]]])->get()->map(fn ($r) => $r->data['title'])->all();

        $this->assertSame(['hasnt'], $titles('empty'));
        $this->assertSame(['has'], $titles('not_empty'));
    }

    public function test_bulk_contact_actions_use_a_constant_number_of_queries(): void
    {
        $ids = collect(range(1, 40))->map(fn ($i) => Contact::create(['first_name' => "B$i", 'last_name' => 'Bulk', 'email' => "b$i@example.com"])->id)->all();

        DB::enableQueryLog();
        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'add_tag', 'value' => 'many'])->assertOk()->assertJson(['affected' => 40]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "bulk tagging 40 contacts ran {$queries} queries");
        $this->assertSame(40, DB::table('contact_tag')->count());
    }
}
