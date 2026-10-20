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
}
