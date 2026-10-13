<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DataObject;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Bugs found by driving every form through the browser (tests/e2e); kept here as fast regression tests. */
class FormAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_values_are_stored_with_real_json_types(): void
    {
        $deals = DataObject::create(['name' => 'Deals']);
        $deals->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'position' => 1],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'position' => 2],
            ['key' => 'hot', 'label' => 'Hot', 'type' => 'checkbox', 'position' => 3],
        ]);

        $clean = app(RecordService::class)->validate($deals->load('fields'), ['title' => 'x', 'amount' => '777', 'hot' => '1']);
        $this->assertSame(777, $clean['amount']);
        $this->assertSame(12.5, app(RecordService::class)->validate($deals, ['amount' => '12.5'], partial: true)['amount']);
        $this->assertTrue($clean['hot']);
        $this->assertSame(['amount' => null], app(RecordService::class)->validate($deals, ['amount' => null], partial: true));
    }

    public function test_contact_search_matches_a_full_name_and_still_matches_single_words(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Contact::create(['first_name' => 'Greta', 'last_name' => 'Findable', 'email' => 'g@example.com']);
        Contact::create(['first_name' => 'Greta', 'last_name' => 'Other', 'email' => 'o@example.com']);

        $this->getJson('/api/v1/contacts?filter[q]=Greta Findable')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts?filter[q]=Greta')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/contacts?filter[q]=g@example.com')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_last_relationship_type_can_be_removed_and_a_query_may_have_no_filters(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson('/api/v1/contact-relationship-types', ['items' => [['label' => 'Spouse', 'kind' => 'direct']]])->assertOk();
        $this->putJson('/api/v1/contact-relationship-types', ['items' => []])->assertOk();
        $this->getJson('/api/v1/contact-relationship-types')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/queries', ['name' => 'Everyone', 'scope' => 'contact', 'definition' => []])->assertCreated();
    }

    public function test_an_automation_without_conditions_runs_for_every_item(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $deals = DataObject::create(['name' => 'Deals']);

        $this->postJson('/api/v1/automations', ['name' => 'Tag all', 'data_object_id' => $deals->id, 'actions' => [['type' => 'add_tag', 'tag' => 'x']]])
            ->assertCreated();
        $this->postJson('/api/v1/automations', ['name' => 'Tag all 2', 'data_object_id' => $deals->id, 'conditions' => [], 'actions' => [['type' => 'add_tag', 'tag' => 'x']]])
            ->assertCreated();
    }

    public function test_a_non_numeric_id_in_a_page_url_is_a_404_not_a_server_error(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        foreach (['/contacts/undefined', '/boards/undefined', '/objects/undefined', '/objects/undefined/edit'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->get('/objects/new')->assertOk();
    }

    public function test_cursor_paging_does_not_skip_rows_that_share_a_timestamp(): void
    {
        Sanctum::actingAs($admin = User::factory()->create(['role' => 'admin']));
        $when = now()->startOfSecond();
        foreach (range(1, 7) as $i) {
            Contact::forceCreate(['first_name' => 'Same', 'last_name' => "Second$i", 'email' => "s$i@example.com", 'created_at' => $when, 'updated_at' => $when]);
        }

        $seen = [];
        $url = '/api/v1/contacts?per_page=3&filter[q]=Same';
        do {
            $page = $this->getJson($url)->assertOk()->json();
            $seen = array_merge($seen, array_column($page['data'], 'id'));
            $url = $page['meta']['next_cursor'] ? '/api/v1/contacts?per_page=3&filter[q]=Same&cursor='.$page['meta']['next_cursor'] : null;
        } while ($url);

        $this->assertCount(7, array_unique($seen));
        $this->assertCount(7, $seen);

        $this->getJson('/api/v1/tasks?per_page=100')->assertOk();
    }

    public function test_notification_times_are_iso_8601_so_browsers_do_not_read_them_as_local_time(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        DB::table('notifications_feed')->insert(['user_id' => $user->id, 'title' => 'Hello', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00']);

        $at = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0.created_at');

        $this->assertMatchesRegularExpression('/^2026-01-01T12:00:00(Z|\+00:00)$/', $at);
    }
}
