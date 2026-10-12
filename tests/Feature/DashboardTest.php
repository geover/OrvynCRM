<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\PermissionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_trends_against_the_previous_30_days_and_recent_activity(): void
    {
        Cache::flush();
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $recent = Contact::create(['first_name' => 'New', 'last_name' => 'One', 'email' => 'n1@example.com']);
        $old = Contact::create(['first_name' => 'Old', 'last_name' => 'One', 'email' => 'o1@example.com']);
        $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        Activity::create(['contact_id' => $recent->id, 'causer_id' => $user->id, 'type' => 'note.created', 'data' => ['title' => 'Called them']]);

        $d = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertSame(1, $d['new_contacts_30d']);
        $this->assertSame(1, $d['previous']['new_contacts_30d']);                   // the 40-day-old contact is in the prior window
        $this->assertSame(0, $d['previous']['emails_sent_30d']);
        $this->assertSame('note.created', $d['recent_activity'][0]['type']);
        $this->assertSame('Called them', $d['recent_activity'][0]['summary']);
        $this->assertSame($recent->id, $d['recent_activity'][0]['contact']['id']);
        $this->assertSame($user->name, $d['recent_activity'][0]['by']);
    }

    public function test_users_who_cannot_view_contacts_get_no_activity_feed(): void
    {
        Cache::flush();
        $template = PermissionTemplate::create(['name' => 'No contacts', 'permissions' => ['contacts' => []]]);
        $contact = Contact::create(['first_name' => 'Secret', 'last_name' => 'Person', 'email' => 's@example.com']);
        Activity::create(['contact_id' => $contact->id, 'type' => 'note.created', 'data' => ['title' => 'Private']]);
        Sanctum::actingAs(User::factory()->create(['role' => 'user', 'permission_template_id' => $template->id]));

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.recent_activity', []);
    }
}
