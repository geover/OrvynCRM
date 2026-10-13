<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\Announcement;
use Illuminate\Support\Facades\Cache;
use Tests\TenantTestCase;

/** Platform announcements: written on the platform, read on the sign-in page and inside every company. */
class AnnouncementsTest extends TenantTestCase
{
    private function platformAdmin(): void
    {
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    private function payload(array $over = []): array
    {
        return $over + ['title' => 'Faster search', 'body' => "Contacts search is **much** faster.\n\n- prefix search\n- better ranking", 'category' => 'improvement', 'publish' => 'now', 'show_on_login' => true, 'show_in_app' => true];
    }

    public function test_only_platform_administrators_manage_announcements(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->get('/platform/announcements')->assertForbidden();
        $this->post('/platform/announcements', $this->payload())->assertForbidden();
        $this->assertSame(0, Announcement::count());
    }

    public function test_publishing_scheduling_drafting_and_validation(): void
    {
        $this->platformAdmin();
        $this->get('/platform/announcements')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Announcements')->has('announcements', 0));

        $this->post('/platform/announcements', $this->payload())->assertRedirect()->assertSessionHas('success', 'Published.');
        $this->post('/platform/announcements', $this->payload(['title' => 'Later', 'publish' => 'scheduled', 'published_at' => now()->addDays(2)->toDateTimeString()]))->assertSessionHas('success', 'Scheduled.');
        $this->post('/platform/announcements', $this->payload(['title' => 'Unfinished', 'publish' => 'draft']))->assertSessionHas('success', 'Saved as a draft.');
        $this->assertSame(['live', 'scheduled', 'draft'], Announcement::orderBy('id')->get()->map->status->all());

        $this->post('/platform/announcements', $this->payload(['title' => '', 'category' => 'rumour', 'publish' => 'scheduled']))->assertSessionHasErrors(['title', 'category', 'published_at']);
        $this->post('/platform/announcements', $this->payload(['expires_at' => now()->subDay()->toDateTimeString()]))->assertSessionHasErrors('expires_at');

        $draft = Announcement::where('title', 'Unfinished')->first();
        $this->post("/platform/announcements/{$draft->id}/publish", ['published' => true])->assertRedirect();
        $this->assertSame('live', $draft->fresh()->status);
        $this->post("/platform/announcements/{$draft->id}/publish", ['published' => false])->assertRedirect();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->delete("/platform/announcements/{$draft->id}")->assertUnprocessable();                        // only archived ones can be deleted
        $this->assertNotNull(Announcement::find($draft->id));
        $this->post("/platform/announcements/{$draft->id}/archive")->assertRedirect();
        $this->delete("/platform/announcements/{$draft->id}")->assertRedirect();
        $this->assertNull(Announcement::find($draft->id));
    }

    public function test_the_sign_in_page_shows_only_live_announcements_chosen_for_it_newest_and_pinned_first(): void
    {
        Cache::flush();
        Announcement::create(['title' => 'Old news', 'body' => 'x', 'category' => 'news', 'published_at' => now()->subDays(5)]);
        Announcement::create(['title' => 'Pinned', 'body' => 'x', 'category' => 'update', 'published_at' => now()->subDays(9), 'pinned' => true]);
        Announcement::create(['title' => 'New thing', 'body' => 'x', 'category' => 'update', 'published_at' => now()->subHour()]);
        Announcement::create(['title' => 'Draft', 'body' => 'x', 'category' => 'update']);
        Announcement::create(['title' => 'Scheduled', 'body' => 'x', 'category' => 'update', 'published_at' => now()->addDay()]);
        Announcement::create(['title' => 'Expired', 'body' => 'x', 'category' => 'update', 'published_at' => now()->subDays(3), 'expires_at' => now()->subDay()]);
        Announcement::create(['title' => 'In app only', 'body' => 'x', 'category' => 'update', 'published_at' => now()->subHour(), 'show_on_login' => false]);

        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->has('signInUpdates', 3)
            ->where('signInUpdates.0.title', 'Pinned')->where('signInUpdates.1.title', 'New thing')->where('signInUpdates.2.title', 'Old news')
            ->missing('signInUpdates.0.show_on_login'));                                                  // readers never get the admin-only fields
        $this->get('/forgot-password')->assertInertia(fn ($p) => $p->where('signInUpdates', []));       // the login page only
    }

    public function test_companies_read_them_under_whats_new_and_get_the_ids_for_the_badge(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $mine = Announcement::create(['title' => 'For companies', 'body' => 'Hello', 'category' => 'news', 'published_at' => now()->subHour()]);
        Announcement::create(['title' => 'Login only', 'body' => 'x', 'category' => 'news', 'published_at' => now()->subHour(), 'show_in_app' => false]);
        Announcement::create(['title' => 'Draft', 'body' => 'x', 'category' => 'news']);

        foreach (['owner@acme.test', 'owner@beta.test'] as $email) {                                   // every company sees the same
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $this->post('/login', ['email' => $email, 'password' => 'Owner-Passw0rd-1']);
            $this->get('/')->assertInertia(fn ($p) => $p->where('updates.ids', [$mine->id]));
            $this->get('/updates')->assertOk()->assertInertia(fn ($p) => $p->component('Updates/Index')->has('announcements', 1)->where('announcements.0.title', 'For companies'));
        }

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/updates')->assertRedirect('/login');                                              // signed-in people only
    }

    public function test_platform_pages_do_not_carry_the_companys_updates_badge(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        Announcement::create(['title' => 'Hi', 'body' => 'x', 'category' => 'news', 'published_at' => now()->subHour()]);
        $this->platformAdmin();
        $this->withSession(['tenant_id' => $acme->id])->get('/platform/announcements')->assertOk()->assertInertia(fn ($p) => $p->where('updates', null));
    }

    public function test_only_unpublished_or_ended_updates_can_be_archived_and_restoring_brings_them_back_as_they_were(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $live = Announcement::create(['title' => 'Live one', 'body' => 'x', 'category' => 'news', 'published_at' => now()->subHour()]);
        $scheduled = Announcement::create(['title' => 'Scheduled one', 'body' => 'x', 'category' => 'news', 'published_at' => now()->addDay()]);
        $ended = Announcement::create(['title' => 'Ended one', 'body' => 'x', 'category' => 'news', 'published_at' => now()->subDays(3), 'expires_at' => now()->subDay()]);
        $draft = Announcement::create(['title' => 'Draft one', 'body' => 'x', 'category' => 'news']);
        $this->platformAdmin();

        // still published or showing: refused until it is unpublished
        $this->post("/platform/announcements/{$live->id}/archive")->assertUnprocessable();
        $this->post("/platform/announcements/{$scheduled->id}/archive")->assertUnprocessable();
        $this->assertFalse($live->fresh()->isArchived());
        $this->assertFalse($scheduled->fresh()->isArchived());

        // a draft and an ended update can be archived
        $this->post("/platform/announcements/{$draft->id}/archive")->assertRedirect()->assertSessionHas('success', 'Archived.');
        $this->post("/platform/announcements/{$ended->id}/archive")->assertRedirect();
        $this->assertTrue($draft->fresh()->isArchived() && $ended->fresh()->isArchived());

        // unpublish the live one, then it can be archived
        $this->post("/platform/announcements/{$live->id}/publish", ['published' => false])->assertRedirect();
        $this->post("/platform/announcements/{$live->id}/archive")->assertRedirect();
        $this->assertTrue($live->fresh()->isArchived());

        $this->get('/platform/announcements')->assertInertia(fn ($p) => $p->has('announcements', 4)->where('announcements', fn ($rows) => collect($rows)->whereNotNull('archived_at')->count() === 3));
        $this->post("/platform/announcements/{$live->id}/publish", ['published' => true])->assertUnprocessable();         // an archived one is read-only
        $this->patch("/platform/announcements/{$live->id}", ['title' => 'Changed', 'body' => 'x', 'category' => 'news', 'publish' => 'now'])->assertUnprocessable();

        $this->post("/platform/announcements/{$ended->id}/unarchive")->assertRedirect()->assertSessionHas('success', 'Restored.');
        $this->assertSame('expired', $ended->fresh()->status);                                                           // back as it was
        $this->post("/platform/announcements/{$live->id}/unarchive")->assertRedirect();
        $this->assertSame('draft', $live->fresh()->status);                                                              // it was unpublished when archived
    }

    public function test_an_archived_update_is_gone_from_the_sign_in_page_and_stays_gone(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $update = Announcement::create(['title' => 'Showing', 'body' => 'x', 'category' => 'news', 'published_at' => now()->subHour()]);
        Cache::flush();
        $this->get('/login')->assertInertia(fn ($p) => $p->has('signInUpdates', 1));

        $this->platformAdmin();
        $this->post("/platform/announcements/{$update->id}/publish", ['published' => false])->assertRedirect();
        $this->post("/platform/announcements/{$update->id}/archive")->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->has('signInUpdates', 0));

        $this->platformAdmin();
        $this->post("/platform/announcements/{$update->id}/unarchive")->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/login')->assertInertia(fn ($p) => $p->has('signInUpdates', 0), 'restored as a draft: nothing is published again by itself');
    }

    public function test_company_admins_cannot_archive_or_delete_updates(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $a = Announcement::create(['title' => 'x', 'body' => 'x', 'category' => 'news', 'published_at' => now()->subHour()]);
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->post("/platform/announcements/{$a->id}/archive")->assertForbidden();
        $this->delete("/platform/announcements/{$a->id}")->assertForbidden();
        $this->assertFalse($a->fresh()->isArchived());
    }
}
