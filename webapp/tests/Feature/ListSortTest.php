<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DataObject;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The list tables sort by any column header: contacts by name, phone, owner and so on; records by any of their fields. */
class ListSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_contacts_sort_by_name_phone_and_owner_in_both_directions(): void
    {
        $zed = User::factory()->create(['name' => 'Zed Owner']);
        $amy = User::factory()->create(['name' => 'Amy Owner']);
        Contact::create(['first_name' => 'Bob', 'last_name' => 'Adams', 'phone' => '300', 'owner_id' => $zed->id]);
        Contact::create(['first_name' => 'Alice', 'last_name' => 'Young', 'phone' => '100', 'owner_id' => $amy->id]);
        Contact::create(['first_name' => 'Alice', 'last_name' => 'Brown', 'phone' => '200', 'owner_id' => null]);

        $names = fn (string $sort) => collect($this->getJson("/api/v1/contacts?sort={$sort}")->assertOk()->json('data'))->pluck('full_name')->all();

        $this->assertSame(['Alice Brown', 'Alice Young', 'Bob Adams'], $names('full_name'));
        $this->assertSame(['Bob Adams', 'Alice Young', 'Alice Brown'], $names('-full_name'));
        $this->assertSame(['Alice Young', 'Alice Brown', 'Bob Adams'], $names('phone'));
        $this->assertSame(['Alice Brown', 'Alice Young', 'Bob Adams'], $names('owner'));       // nobody first, then Amy, then Zed
        $this->assertSame(['Bob Adams', 'Alice Young', 'Alice Brown'], $names('-owner'));
        $this->getJson('/api/v1/contacts?sort=password')->assertStatus(400);                  // only the known columns
    }

    public function test_records_sort_by_an_indexed_and_a_plain_field_and_page_through_the_order(): void
    {
        $object = DataObject::create(['name' => 'Deals']);
        $object->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'is_indexed' => true],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text'],
        ]);
        $object = $object->fresh('fields');
        foreach ([['B', 30, 'north'], ['A', 5, 'west'], ['C', 100, 'east']] as [$title, $amount, $region]) {
            app(RecordService::class)->create($object, ['title' => $title, 'amount' => $amount, 'region' => $region]);
        }
        $url = "/api/v1/data-objects/{$object->id}/records";
        $titles = fn (string $query) => collect($this->getJson("{$url}?{$query}")->assertOk()->json('data'))->map(fn ($r) => $r['data']['title'] ?? $r['title'])->all();

        $this->assertSame(['A', 'B', 'C'], $titles('sort=amount'));
        $this->assertSame(['C', 'B', 'A'], $titles('sort=-amount'));      // numbers sort as numbers: 100 after 30
        $this->assertSame(['C', 'B', 'A'], $titles('sort=region'));       // east, north, west
        $this->assertSame(['A', 'B', 'C'], $titles('sort=-region'));
        $this->assertSame(['C', 'A', 'B'], $titles('sort=nonsense'));     // unknown: newest first, as before

        $first = $this->getJson("{$url}?sort=-amount&per_page=2")->assertOk();
        $second = $this->getJson("{$url}?sort=-amount&per_page=2&cursor=".$first->json('meta.next_cursor'))->assertOk();
        $this->assertSame(['C', 'B'], collect($first->json('data'))->map(fn ($r) => $r['data']['title'] ?? $r['title'])->all());
        $this->assertSame(['A'], collect($second->json('data'))->map(fn ($r) => $r['data']['title'] ?? $r['title'])->all());
    }
}
