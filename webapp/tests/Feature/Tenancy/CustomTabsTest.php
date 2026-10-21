<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Tests\TenantTestCase;

/** Custom tabs: a tab on every contact, or a page in the main menu, for everybody or only for chosen roles and people. */
class CustomTabsTest extends TenantTestCase
{
    private Tenant $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
    }

    private function signedInAs(string $email, string $password): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $password, 'company' => 'alpha'])->assertOk()->json('token');

        return $this->withToken($token);
    }

    private function admin(): static
    {
        return $this->signedInAs('admin@alpha.test', 'Owner-Passw0rd-1');
    }

    private function titles(string $email): array
    {
        $password = $email === 'admin@alpha.test' ? 'Owner-Passw0rd-1' : 'Newbie-Passw0rd-1';

        return collect($this->signedInAs($email, $password)->getJson('/api/v1/custom-tabs')->assertOk()->json('data'))->pluck('title')->all();
    }

    public function test_a_tab_is_seen_by_the_people_it_is_meant_for_and_by_administrators(): void
    {
        $manager = $this->admin()->postJson('/api/v1/users', ['name' => 'Mia', 'email' => 'mia@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'manager'])->assertCreated()->json('data.id');
        $this->admin()->postJson('/api/v1/users', ['name' => 'Uli', 'email' => 'uli@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user'])->assertCreated();
        $other = $this->admin()->postJson('/api/v1/users', ['name' => 'Ola', 'email' => 'ola@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user'])->assertCreated()->json('data.id');

        $this->admin()->putJson('/api/v1/custom-tabs', ['items' => [
            ['title' => 'Docs', 'type' => 'iframe', 'content' => 'https://example.com/?c={{contact.id}}', 'placement' => 'contact', 'audience' => null],
            ['title' => 'Handbook', 'type' => 'html', 'content' => '<h2>Hi</h2>', 'placement' => 'sidebar', 'icon' => 'book-open', 'audience' => ['roles' => ['manager']]],
            ['title' => 'Rota', 'type' => 'html', 'content' => '<p>Rota</p>', 'placement' => 'sidebar', 'audience' => ['users' => [$other]]],
        ]])->assertOk();

        $this->assertSame(['Docs', 'Handbook', 'Rota'], $this->titles('admin@alpha.test'));        // administrators see everything, to check it
        $this->assertSame(['Docs', 'Handbook'], $this->titles('mia@alpha.test'));                 // by role
        $this->assertSame(['Docs'], $this->titles('uli@alpha.test'));                            // neither the role nor named
        $this->assertSame(['Docs', 'Rota'], $this->titles('ola@alpha.test'));                    // named

        $handbook = collect($this->admin()->getJson('/api/v1/custom-tabs')->json('data'))->firstWhere('title', 'Handbook');
        $this->assertSame('sidebar', $handbook['placement']);
        $this->signedInAs('mia@alpha.test', 'Newbie-Passw0rd-1')->getJson("/api/v1/custom-tabs/{$handbook['id']}")->assertOk()->assertJsonPath('data.content', '<h2>Hi</h2>');
        $this->signedInAs('uli@alpha.test', 'Newbie-Passw0rd-1')->getJson("/api/v1/custom-tabs/{$handbook['id']}")->assertNotFound();    // not hidden by the screen alone: the server refuses it
        $this->assertNotNull($manager);
    }

    public function test_only_administrators_change_tabs_and_the_rules_are_enforced(): void
    {
        $this->admin()->postJson('/api/v1/users', ['name' => 'Uli', 'email' => 'uli@alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user'])->assertCreated();
        $tab = ['title' => 'Docs', 'type' => 'html', 'content' => '<p>x</p>', 'placement' => 'sidebar'];

        $this->signedInAs('uli@alpha.test', 'Newbie-Passw0rd-1')->putJson('/api/v1/custom-tabs', ['items' => [$tab]])->assertForbidden();
        $this->admin()->putJson('/api/v1/custom-tabs', ['items' => [['type' => 'iframe', 'content' => 'http://insecure.example.com'] + $tab]])->assertUnprocessable();         // https only
        $this->admin()->putJson('/api/v1/custom-tabs', ['items' => [$tab + ['audience' => ['roles' => []]]]])->assertUnprocessable();                                            // "chosen" with nobody chosen
        $this->admin()->putJson('/api/v1/custom-tabs', ['items' => [['placement' => 'footer'] + $tab]])->assertUnprocessable();
        $this->admin()->putJson('/api/v1/custom-tabs', ['items' => [$tab + ['icon' => 'not-an-icon']]])->assertUnprocessable();
        $this->assertSame([], $this->admin()->getJson('/api/v1/custom-tabs')->json('data'));
    }
}
