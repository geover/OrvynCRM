<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountSession;
use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\PermissionTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The people of a company: their basic details and photo, and when they were last here, as the company's administrators and the platform see them. */
class PeopleProfilesTest extends TenantTestCase
{
    private Tenant $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $this->postJson('/api/v1/auth/token', ['email' => 'admin@alpha.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'alpha'])->assertOk()->json('token');

        return $this->withToken($token);
    }

    private function person(string $email): array
    {
        return collect($this->asAdmin()->getJson('/api/v1/users')->assertOk()->json('data'))->firstWhere('email', $email);
    }

    public function test_a_new_person_can_be_given_basic_details_and_they_are_listed_with_their_activity(): void
    {
        $this->asAdmin()->postJson('/api/v1/users', ['name' => 'Newbie', 'email' => 'newbie@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user',
            'job_title' => 'Sales rep', 'phone' => '+1 555 0100', 'timezone' => 'Europe/Berlin', 'notes' => 'Starts Monday'])->assertCreated();

        $p = $this->person('newbie@alpha.test');
        $this->assertSame('Sales rep', $p['job_title']);
        $this->assertSame('Europe/Berlin', $p['timezone']);
        $this->assertSame('Starts Monday', $p['notes']);
        $this->assertSame('never', $p['activity']);                          // nobody has signed in as them yet
        $this->assertNull($p['avatar_url']);
        $this->assertSame(0, $p['sessions_30d']);
    }

    public function test_activity_counts_only_the_sessions_that_worked_in_this_company(): void
    {
        $this->asAdmin()->postJson('/api/v1/users', ['name' => 'Newbie', 'email' => 'newbie@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user'])->assertCreated();
        $id = AccountUser::where('email', 'newbie@alpha.test')->value('id');
        AccountSession::create(['account_user_id' => $id, 'tenant_id' => $this->alpha->id, 'started_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(2), 'method' => 'password']);
        AccountSession::create(['account_user_id' => $id, 'tenant_id' => 'some-other-company', 'started_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3)->addHours(5), 'method' => 'password']);

        $p = $this->person('newbie@alpha.test');
        $this->assertSame('online', $p['activity']);
        $this->assertSame(1, $p['sessions_30d']);                             // the other company's session is not this company's business
        $this->assertGreaterThan(3000, $p['seconds_30d']);
        $this->assertCount(1, $p['recent']);
    }

    public function test_details_and_photo_can_be_changed_and_the_photo_is_served_to_the_company(): void
    {
        $id = $this->asAdmin()->postJson('/api/v1/users', ['name' => 'Newbie', 'email' => 'newbie@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user'])->json('data.id');

        $this->asAdmin()->patchJson("/api/v1/users/$id", ['job_title' => 'Head of sales', 'phone' => '+44 20 7946 0000'])->assertOk();
        $this->asAdmin()->patchJson("/api/v1/users/$id", ['timezone' => 'Not/AZone'])->assertUnprocessable();

        $this->asAdmin()->post("/api/v1/users/$id/avatar", ['file' => UploadedFile::fake()->image('me.png', 800, 500)], ['Accept' => 'application/json'])->assertOk();
        $p = $this->person('newbie@alpha.test');
        $this->assertSame('Head of sales', $p['job_title']);
        $this->assertStringContainsString("/files/users/$id/avatar?v=", $p['avatar_url']);
        $this->alpha->run(function () use ($id) {
            [$w, $h] = getimagesizefromstring(Storage::disk('local')->get(User::find($id)->avatar_path));
            $this->assertSame([256, 256], [$w, $h]);                          // a small square
        });
        $this->asAdmin()->post("/api/v1/users/$id/avatar", ['file' => UploadedFile::fake()->create('x.php', 1)], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->asAdmin()->deleteJson("/api/v1/users/$id/avatar")->assertOk();
        $this->assertNull($this->person('newbie@alpha.test')['avatar_url']);
    }

    public function test_the_platform_sees_the_same_for_a_company_and_can_change_it_without_opening_it(): void
    {
        $id = $this->asAdmin()->postJson('/api/v1/users', ['name' => 'Newbie', 'email' => 'newbie@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user', 'job_title' => 'Sales rep'])->json('data.id');

        $this->defaultHeaders = [];
        $this->app['auth']->forgetGuards();
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->get("/platform/companies/{$this->alpha->id}/users")->assertOk()->assertInertia(function ($p) {
            $p->component('Platform/CompanyUsers');
            $by = collect($p->toArray()['props']['users'])->keyBy('email');
            $this->assertSame('Sales rep', $by['newbie@alpha.test']['job_title']);
            $this->assertSame('never', $by['newbie@alpha.test']['activity']);
        });

        $this->patch("/platform/companies/{$this->alpha->id}/users/$id", ['job_title' => 'Head of sales', 'notes' => 'Key person'])->assertRedirect();
        $this->post("/platform/companies/{$this->alpha->id}/users/$id/avatar", ['file' => UploadedFile::fake()->image('me.png')])->assertRedirect();
        $this->get("/platform/companies/{$this->alpha->id}/users/$id/avatar")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->alpha->run(function () use ($id) {
            $this->assertSame('Head of sales', User::find($id)->job_title);
            $this->assertNotNull(User::find($id)->avatar_path);
        });
    }

    public function test_the_platform_form_changes_everything_the_company_form_does(): void
    {
        $template = $this->alpha->run(fn () => PermissionTemplate::create(['name' => 'Viewers', 'permissions' => [], 'is_admin' => false])->id);
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        // adding: role, permission template, a password with its confirmation, and the link to the contact with that email
        $this->alpha->run(fn () => Contact::create(['first_name' => 'Lena', 'email' => 'lena@alpha.test']));
        $this->post("/platform/companies/{$this->alpha->id}/users", ['name' => 'Lena', 'email' => 'lena@alpha.test', 'role' => 'manager', 'permission_template_id' => $template,
            'password' => 'Lena-Passw0rd-12', 'password_confirmation' => 'Lena-Passw0rd-12', 'link_contact' => true, 'job_title' => 'Buyer'])->assertRedirect();
        $this->post("/platform/companies/{$this->alpha->id}/users", ['name' => 'X', 'email' => 'x@alpha.test', 'role' => 'user', 'permission_template_id' => 9999])->assertSessionHasErrors();
        $lena = $this->alpha->run(fn () => User::where('email', 'lena@alpha.test')->first());
        $this->assertSame([$template, 'manager', 'Buyer'], [$lena->permission_template_id, $lena->role, $lena->job_title]);
        $this->assertNotNull($lena->contact_id);

        // editing: name, email, role, status, template
        $this->patch("/platform/companies/{$this->alpha->id}/users/{$lena->id}", ['name' => 'Lena K', 'role' => 'user', 'status' => 'suspended', 'permission_template_id' => null])->assertRedirect();
        $lena = $this->alpha->run(fn () => User::find($lena->id));
        $this->assertSame(['Lena K', 'user', 'suspended', null], [$lena->name, $lena->role, $lena->status, $lena->permission_template_id]);

        // the last active administrator cannot be demoted or suspended
        $owner = $this->alpha->run(fn () => User::where('email', 'admin@alpha.test')->first());
        $this->patch("/platform/companies/{$this->alpha->id}/users/{$owner->id}", ['status' => 'suspended'])->assertSessionHasErrors('role');
    }

    public function test_a_platform_administrator_inside_a_company_keeps_their_photo_in_the_header(): void
    {
        $admin = AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
        $this->post("/platform/administrators/{$admin->id}/avatar", ['file' => UploadedFile::fake()->image('me.png', 300, 300)])->assertRedirect();

        $this->post("/platform/companies/{$this->alpha->id}/open")->assertRedirect();
        $url = null;
        $this->get('/')->assertOk()->assertInertia(function ($p) use (&$url) {
            $url = $p->toArray()['props']['auth']['user']['avatar_url'];
        });
        $this->assertStringContainsString('/files/users/', $url);                       // the company's own file route: the platform's address would leave the company

        $this->get(strtok($url, '?'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_picture_with_a_transparent_background_stays_transparent_and_an_opaque_one_is_a_jpeg(): void
    {
        $id = $this->asAdmin()->postJson('/api/v1/users', ['name' => 'Newbie', 'email' => 'newbie@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user'])->json('data.id');

        $img = imagecreatetruecolor(64, 64);                                          // an avatar: a coloured disc on nothing
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledellipse($img, 32, 32, 40, 40, imagecolorallocate($img, 200, 50, 50));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $this->asAdmin()->post("/api/v1/users/$id/avatar", ['file' => UploadedFile::fake()->createWithContent('avatar.png', $png)], ['Accept' => 'application/json'])->assertOk();
        $this->alpha->run(fn () => $this->assertStringEndsWith('.png', User::find($id)->avatar_path));
        $this->asAdmin()->get("/files/users/$id/avatar")->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->asAdmin()->post("/api/v1/users/$id/avatar", ['file' => UploadedFile::fake()->image('me.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();      // a photo: opaque
        $this->alpha->run(function () use ($id) {
            $this->assertStringEndsWith('.jpg', User::find($id)->avatar_path);
            $this->assertFalse(Storage::disk('local')->exists("avatars/users/$id.png"));                  // the old picture is not left behind
        });
        $this->asAdmin()->get("/files/users/$id/avatar")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_the_add_platform_administrator_form_takes_the_basic_details(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);

        $this->post('/platform/administrators', ['name' => 'Second', 'email' => 'second@x.test', 'password' => 'Second-Passw0rd-1', 'job_title' => 'On-call engineer', 'phone' => '+1 555 0199', 'timezone' => 'Asia/Tokyo', 'notes' => 'Weekends'])->assertRedirect();

        $second = AccountUser::where('email', 'second@x.test')->first();
        $this->assertTrue($second->is_platform_admin);
        $this->assertSame(['On-call engineer', '+1 555 0199', 'Asia/Tokyo', 'Weekends'], [$second->job_title, $second->phone, $second->timezone, $second->notes]);
    }
}
