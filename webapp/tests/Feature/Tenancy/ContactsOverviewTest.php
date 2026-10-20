<?php

namespace Tests\Feature\Tenancy;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Tests\TenantTestCase;

/** The numbers above the contact list, and the counts the list carries for its cards. */
class ContactsOverviewTest extends TenantTestCase
{
    private Tenant $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $this->postJson('/api/v1/auth/token', ['email' => 'admin@alpha.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'alpha'])->assertOk()->json('token');

        return $this->withToken($token);
    }

    public function test_the_overview_counts_people_new_ones_mine_open_work_and_opt_outs_and_the_list_counts_tasks_and_notes(): void
    {
        $this->alpha->run(function () {
            $me = User::where('email', 'admin@alpha.test')->first();
            $ana = Contact::create(['first_name' => 'Ana', 'last_name' => 'One', 'email' => 'ana@x.test', 'owner_id' => $me->id]);
            $bo = Contact::create(['first_name' => 'Bo', 'last_name' => 'Two', 'email' => 'bo@x.test', 'email_opt_out' => true]);
            $old = Contact::create(['first_name' => 'Old', 'last_name' => 'Timer', 'email' => 'old@x.test']);
            $old->forceFill(['created_at' => now()->subDays(90)])->save();

            Task::create(['contact_id' => $ana->id, 'title' => 'Call', 'type' => 'task']);                                           // open
            Task::create(['contact_id' => $ana->id, 'title' => 'Done', 'type' => 'task', 'completed_at' => now()]);                  // finished: does not count
            $ana->notes()->create(['body' => 'A note', 'user_id' => $me->id]);
            $tag = Tag::create(['name' => 'vip']);
            $ana->tags()->attach($tag->id);
            $bo->tags()->attach($tag->id);
        });

        $o = $this->asAdmin()->getJson('/api/v1/contacts/overview')->assertOk()->json('data');
        $this->assertSame(3, $o['total']);
        $this->assertSame(2, $o['new_30d']);
        $this->assertSame(1, $o['mine']);
        $this->assertSame(1, $o['with_open_tasks']);
        $this->assertSame(1, $o['opted_out']);
        $this->assertSame([['name' => 'vip', 'count' => 2]], $o['top_tags']);

        $ana = collect($this->asAdmin()->getJson('/api/v1/contacts?page=1')->assertOk()->json('data'))->firstWhere('email', 'ana@x.test');
        $this->assertSame(1, $ana['open_tasks']);
        $this->assertSame(1, $ana['notes_count']);
    }

    public function test_a_data_objects_overview_counts_items_and_spreads_them_over_its_first_dropdown(): void
    {
        $id = $this->asAdmin()->postJson('/api/v1/data-objects', ['name' => 'Deals', 'fields' => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'show_in_list' => true],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['choices' => ['New', 'Won', 'Lost']], 'is_indexed' => true, 'show_in_list' => true],
            ['key' => 'priority', 'label' => 'Priority', 'type' => 'select', 'options' => ['choices' => ['High', 'Low']], 'is_indexed' => true],
        ]])->assertCreated()->json('data.id');
        foreach ([['A', 'New', 'High'], ['B', 'New', null], ['C', 'Won', null], ['D', null, null]] as [$title, $stage, $priority]) {
            $this->asAdmin()->postJson("/api/v1/data-objects/$id/records", ['data' => array_filter(['title' => $title, 'stage' => $stage, 'priority' => $priority])])->assertCreated();
        }

        $o = $this->asAdmin()->getJson("/api/v1/data-objects/$id/records/overview")->assertOk()->json('data');
        $this->assertSame(4, $o['total']);
        $this->assertSame(4, $o['added_7d']);
        $this->assertSame('stage', $o['spread']['key']);
        $this->assertSame([['value' => 'New', 'count' => 2], ['value' => 'Won', 'count' => 1], ['value' => 'Lost', 'count' => 0]], $o['spread']['values']);
        $this->assertSame(1, $o['spread']['empty']);                                   // the item with no stage
        $this->assertSame(['stage', 'priority'], array_column($o['selects'], 'key'));

        $by = $this->asAdmin()->getJson("/api/v1/data-objects/$id/records/overview?field=priority")->assertOk()->json('data.spread');       // the same items, broken down by another dropdown
        $this->assertSame('priority', $by['key']);
        $this->assertSame([['value' => 'High', 'count' => 1], ['value' => 'Low', 'count' => 0]], $by['values']);
        $this->assertSame(3, $by['empty']);
        $this->assertSame('stage', $this->asAdmin()->getJson("/api/v1/data-objects/$id/records/overview?field=title")->json('data.spread.key'));   // not a dropdown: the default is used
    }
}
