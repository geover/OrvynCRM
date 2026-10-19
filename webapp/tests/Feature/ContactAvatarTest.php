<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A contact's picture: their own photo (cut to a small square once), else a Gravatar code for the browser to try, else the icon chosen by sex. */
class ContactAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
        Storage::fake('local');
    }

    public function test_a_contact_with_an_email_gets_a_gravatar_code_and_never_the_address_itself(): void
    {
        $c = Contact::create(['first_name' => 'Ann', 'email' => ' Ann@X.io ']);
        $res = $this->getJson("/api/v1/contacts/{$c->id}")->assertOk();
        $this->assertSame(hash('sha256', 'ann@x.io'), $res->json('data.gravatar'));
        $res->assertJsonPath('data.avatar_url', null);

        $none = Contact::create(['first_name' => 'No Mail']);
        $this->getJson("/api/v1/contacts/{$none->id}")->assertJsonPath('data.gravatar', null);
    }

    public function test_a_company_can_switch_gravatar_off(): void
    {
        $c = Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
        $this->putJson('/api/v1/settings', ['gravatar' => false])->assertOk()->assertJsonPath('data.gravatar', false);
        $this->getJson("/api/v1/contacts/{$c->id}")->assertJsonPath('data.gravatar', null);
        $this->putJson('/api/v1/settings', ['gravatar' => true])->assertOk();
        $this->getJson("/api/v1/contacts/{$c->id}")->assertJsonPath('data.gravatar', hash('sha256', 'ann@x.io'));
    }

    public function test_an_uploaded_photo_is_resized_served_and_replaces_gravatar(): void
    {
        $c = Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
        $res = $this->post("/api/v1/contacts/{$c->id}/avatar", ['file' => UploadedFile::fake()->image('me.png', 1200, 800)], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString("/files/contacts/{$c->id}/avatar?v=", $res->json('data.avatar_url'));
        $this->assertNull($res->json('data.gravatar'));                     // their own photo wins

        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($c->fresh()->avatar_path));
        $this->assertSame([256, 256], [$w, $h]);                            // a small square, whatever was uploaded
        $this->getJson("/api/v1/contacts/{$c->id}")->assertJsonPath('data.avatar_url', $res->json('data.avatar_url'));

        $this->post("/api/v1/contacts/{$c->id}/avatar", ['file' => UploadedFile::fake()->create('evil.php', 1)], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->deleteJson("/api/v1/contacts/{$c->id}/avatar")->assertOk()->assertJsonPath('data.avatar_url', null)->assertJsonPath('data.gravatar', hash('sha256', 'ann@x.io'));
        Storage::disk('local')->assertMissing("avatars/{$c->id}.jpg");
    }

    public function test_sex_is_saved_and_limited_to_male_or_female(): void
    {
        $id = $this->postJson('/api/v1/contacts', ['first_name' => 'Eva', 'email' => 'eva@x.io', 'sex' => 'female'])->assertCreated()->assertJsonPath('data.sex', 'female')->json('data.id');
        $this->patchJson("/api/v1/contacts/$id", ['sex' => 'male'])->assertOk()->assertJsonPath('data.sex', 'male');
        $this->patchJson("/api/v1/contacts/$id", ['sex' => null])->assertOk()->assertJsonPath('data.sex', null);
        $this->patchJson("/api/v1/contacts/$id", ['sex' => 'robot'])->assertUnprocessable();
    }
}
