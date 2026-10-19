<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountSession;
use App\Models\AccountUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** The record of who signed in, when and for how long, and what the platform's Administrators page shows from it. */
class AccountSessionsTest extends TenantTestCase
{
    private AccountUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->defaultHeaders = [];
    }

    private function signIn(): void
    {
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1'])->assertRedirect('/platform');
    }

    public function test_signing_in_opens_a_session_and_signing_out_closes_it(): void
    {
        $this->signIn();
        $session = AccountSession::where('account_user_id', $this->admin->id)->sole();
        $this->assertSame('password', $session->method);
        $this->assertFalse($session->two_step);
        $this->assertNull($session->ended_at);
        $this->assertNotNull($session->ip);

        $this->post('/logout');
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_each_sign_in_is_its_own_session(): void
    {
        $this->signIn();
        $this->post('/logout');
        $this->signIn();

        $this->assertSame(2, AccountSession::count());
        $this->assertSame(1, AccountSession::whereNull('ended_at')->count());       // the first was closed by signing out, the second is open
    }

    public function test_requests_keep_it_fresh_at_most_once_a_minute_and_background_polling_does_not(): void
    {
        $this->signIn();
        $session = AccountSession::sole();
        $session->update(['last_seen_at' => now()->subMinutes(10)]);

        $this->get('/platform')->assertOk();                         // within the minute of signing in: not written again
        $this->assertTrue($session->fresh()->last_seen_at->lt(now()->subMinutes(9)));

        $this->travel(2)->minutes();
        $this->getJson('/platform/messages/poll?counts=1');          // the page asking by itself
        $this->assertTrue($session->fresh()->last_seen_at->lt(now()->subMinutes(9)));

        $this->get('/platform')->assertOk();                          // a real visit
        $this->assertTrue($session->fresh()->last_seen_at->gte(now()->subSeconds(5)));
    }

    public function test_the_administrators_page_says_who_is_online_active_or_never_signed_in(): void
    {
        $quiet = AccountUser::create(['name' => 'Quiet One', 'email' => 'quiet@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        AccountSession::create(['account_user_id' => $quiet->id, 'started_at' => now()->subDays(45), 'last_seen_at' => now()->subDays(45)->addHour(), 'method' => 'passkey', 'two_step' => true]);
        $this->signIn();

        $this->get('/platform/administrators')->assertOk()->assertInertia(function ($p) {
            $p->component('Platform/Administrators');
            $by = collect($p->toArray()['props']['administrators'])->keyBy('email');
            $this->assertSame('online', $by['platform@x.test']['activity']);
            $this->assertSame(1, $by['platform@x.test']['sessions_30d']);
            $this->assertSame('inactive', $by['quiet@x.test']['activity']);       // last signed in 45 days ago
            $this->assertSame(0, $by['quiet@x.test']['sessions_30d']);
            $this->assertSame(3600, $by['quiet@x.test']['recent'][0]['seconds']);
            $this->assertSame('passkey', $by['quiet@x.test']['last_method']);
        });
    }

    public function test_an_administrator_has_basic_details_and_a_photo(): void
    {
        Storage::fake('local');
        $this->signIn();

        $this->patch("/platform/administrators/{$this->admin->id}", ['name' => 'Platform Admin', 'job_title' => 'Head of support', 'phone' => '+1 555 0100', 'timezone' => 'Europe/Berlin', 'notes' => 'On call at weekends'])->assertRedirect();
        $this->assertSame('Head of support', $this->admin->fresh()->job_title);
        $this->patch("/platform/administrators/{$this->admin->id}", ['timezone' => 'Not/AZone'])->assertSessionHasErrors('timezone');

        $this->post("/platform/administrators/{$this->admin->id}/avatar", ['file' => UploadedFile::fake()->image('me.png', 900, 600)])->assertRedirect();
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($this->admin->fresh()->avatar_path));
        $this->assertSame([256, 256], [$w, $h]);                                              // a small square
        $this->get("/platform/administrators/{$this->admin->id}/avatar")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->post("/platform/administrators/{$this->admin->id}/avatar", ['file' => UploadedFile::fake()->create('x.php', 1)])->assertSessionHasErrors('file');

        $this->get('/platform/administrators')->assertInertia(fn ($p) => $p->where('administrators.0.job_title', 'Head of support')->where('administrators.0.notes', 'On call at weekends')->has('administrators.0.avatar_url'));

        $this->delete("/platform/administrators/{$this->admin->id}/avatar")->assertRedirect();
        $this->assertNull($this->admin->fresh()->avatar_path);
        $this->get("/platform/administrators/{$this->admin->id}/avatar")->assertNotFound();
    }
}
