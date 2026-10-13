<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\PermissionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function contacts(int $n = 3): array
    {
        return array_map(fn ($i) => Contact::create(['first_name' => "Bulk$i", 'last_name' => 'Row', 'email' => "bulk$i@example.com"])->id, range(1, $n));
    }

    public function test_tag_untag_owner_opt_out_and_delete_apply_to_every_selected_contact(): void
    {
        $ids = $this->contacts();
        $untouched = Contact::create(['first_name' => 'Other', 'last_name' => 'Person', 'email' => 'other@example.com']);

        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'add_tag', 'value' => 'Launch'])->assertOk()->assertJson(['affected' => 3]);
        foreach ($ids as $id) {
            $this->assertSame(['Launch'], Contact::find($id)->tags->pluck('name')->all());
        }
        $this->assertCount(0, $untouched->fresh()->tags);

        $this->postJson('/api/v1/contacts/bulk', ['ids' => [$ids[0]], 'action' => 'remove_tag', 'value' => 'Launch'])->assertOk();
        $this->assertCount(0, Contact::find($ids[0])->tags);
        $this->assertCount(1, Contact::find($ids[1])->tags);

        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'set_owner', 'value' => (string) $this->admin->id])->assertOk();
        $this->assertSame(3, Contact::whereIn('id', $ids)->where('owner_id', $this->admin->id)->count());
        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'set_owner', 'value' => ''])->assertOk();
        $this->assertSame(0, Contact::whereIn('id', $ids)->whereNotNull('owner_id')->count());

        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'opt_out'])->assertOk();
        $this->assertSame(3, Contact::whereIn('id', $ids)->where('email_opt_out', true)->count());
        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'opt_in'])->assertOk();
        $this->assertSame(0, Contact::whereIn('id', $ids)->where('email_opt_out', true)->count());

        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'delete'])->assertOk()->assertJson(['affected' => 3]);
        $this->assertSame(0, Contact::whereIn('id', $ids)->count());
        $this->assertNotNull(Contact::find($untouched->id));                       // only the selection is touched
    }

    public function test_bad_input_is_refused(): void
    {
        $ids = $this->contacts(1);

        $this->postJson('/api/v1/contacts/bulk', ['ids' => [], 'action' => 'delete'])->assertUnprocessable();
        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'explode'])->assertUnprocessable();
        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'add_tag', 'value' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'set_owner', 'value' => '99999'])->assertUnprocessable();
        $this->postJson('/api/v1/contacts/bulk', ['ids' => range(1, 501), 'action' => 'opt_out'])->assertUnprocessable();
    }

    public function test_users_need_edit_or_delete_permission(): void
    {
        $ids = $this->contacts(1);
        $template = PermissionTemplate::create(['name' => 'Viewer', 'permissions' => ['contacts' => ['view']]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'user', 'permission_template_id' => $template->id]));

        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'add_tag', 'value' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/contacts/bulk', ['ids' => $ids, 'action' => 'delete'])->assertForbidden();
        $this->assertNotNull(Contact::find($ids[0]));
    }
}
