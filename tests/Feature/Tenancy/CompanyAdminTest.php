<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

class CompanyAdminTest extends TenantTestCase
{
    private Tenant $alpha;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
        $this->beta = $this->company('Beta', 'admin@beta.test');
    }

    private function as(string $email, string $company): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];                   // withToken() would otherwise stay on for every later request of the test
        $token = $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => 'Owner-Passw0rd-1', 'company' => $company])->assertOk()->json('token');

        return $this->withToken($token);
    }

    public function test_adding_a_new_person_creates_their_account_membership_and_profile(): void
    {
        $id = $this->as('admin@alpha.test', 'alpha')->postJson('/api/v1/users', ['name' => 'Newbie', 'email' => 'Newbie@Alpha.test', 'password' => 'Newbie-Passw0rd-1', 'role' => 'user'])
            ->assertCreated()->assertJsonPath('existing_account', false)->json('data.id');

        $identity = $this->identity('newbie@alpha.test');
        $this->assertTrue(Hash::check('Newbie-Passw0rd-1', $identity->password));
        $this->assertTrue(Membership::where('account_user_id', $identity->id)->where('tenant_id', $this->alpha->id)->exists());
        $this->assertFalse(Membership::where('account_user_id', $identity->id)->where('tenant_id', $this->beta->id)->exists());
        $this->alpha->run(fn () => $this->assertSame($identity->id, User::find($id)->central_user_id));

        $this->defaultHeaders = [];
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'newbie@alpha.test', 'password' => 'Newbie-Passw0rd-1'])->assertRedirect('/');
        $this->assertSame($this->alpha->id, session('tenant_id'));
    }

    public function test_inviting_without_a_password_emails_the_new_person_a_link(): void
    {
        Notification::fake();

        $this->as('admin@alpha.test', 'alpha')->postJson('/api/v1/users', ['name' => 'Invitee', 'email' => 'invitee@alpha.test', 'role' => 'user'])
            ->assertCreated()->assertJsonPath('invited', true);

        Notification::assertSentTo($this->identity('invitee@alpha.test'), ResetPassword::class);
    }

    public function test_adding_someone_who_already_has_an_account_keeps_their_password_and_does_not_invite_again(): void
    {
        Notification::fake();
        $beta = $this->identity('admin@beta.test');
        $before = $beta->password;

        $this->as('admin@alpha.test', 'alpha')->postJson('/api/v1/users', ['name' => 'Whoever', 'email' => 'admin@beta.test', 'password' => 'Hijack-Passw0rd-9', 'role' => 'manager'])
            ->assertCreated()->assertJsonPath('existing_account', true)->assertJsonPath('invited', false);

        $this->assertSame($before, $beta->fresh()->password, 'an administrator of one company cannot change a password that also opens other companies');
        Notification::assertNothingSent();
        $this->assertSame(2, Membership::where('account_user_id', $beta->id)->count());
        $this->alpha->run(fn () => $this->assertSame('manager', User::where('central_user_id', $beta->id)->first()->role));
        $this->beta->run(fn () => $this->assertSame('admin', User::where('central_user_id', $beta->id)->first()->role));     // each company has its own role
    }

    public function test_shared_credentials_cannot_be_changed_from_one_company(): void
    {
        $shared = $this->identity('admin@beta.test');
        app(TenantProvisioner::class)->addMember($this->alpha, $shared, 'user');
        $profileId = $this->alpha->run(fn () => User::where('central_user_id', $shared->id)->value('id'));
        $soleId = $this->alpha->run(fn () => User::where('central_user_id', $this->identity('admin@alpha.test')->id)->value('id'));
        $api = $this->as('admin@alpha.test', 'alpha');

        $api->patchJson("/api/v1/users/{$profileId}", ['password' => 'Changed-Passw0rd-9'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $api->patchJson("/api/v1/users/{$profileId}", ['email' => 'new-address@x.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('admin@beta.test', $shared->fresh()->email);

        // role, template, caller id are this company's business and may change
        $api->patchJson("/api/v1/users/{$profileId}", ['role' => 'manager', 'caller_id' => '+15550001111'])->assertOk();

        // a person who belongs only here can have everything changed, and it reaches their account
        $api->patchJson("/api/v1/users/{$soleId}", ['name' => 'Renamed Admin'])->assertOk();
        $this->assertSame('Renamed Admin', $this->identity('admin@alpha.test')->name);
    }

    public function test_removing_someone_keeps_their_account_while_they_belong_elsewhere(): void
    {
        $person = $this->identity('admin@beta.test');
        app(TenantProvisioner::class)->addMember($this->alpha, $person, 'user');
        $profileId = $this->alpha->run(fn () => User::where('central_user_id', $person->id)->value('id'));

        $this->as('admin@alpha.test', 'alpha')->deleteJson("/api/v1/users/{$profileId}")->assertNoContent();

        $this->assertNotNull(AccountUser::find($person->id), 'still a member of Beta');
        $this->assertFalse(Membership::where('account_user_id', $person->id)->where('tenant_id', $this->alpha->id)->exists());
        $this->assertTrue(Membership::where('account_user_id', $person->id)->where('tenant_id', $this->beta->id)->exists());
        $this->alpha->run(fn () => $this->assertSame(0, User::where('central_user_id', $person->id)->count()));

        // someone who belonged to this company only is removed completely
        $loner = app(TenantProvisioner::class)->identity('Loner', 'loner@x.test', 'Loner-Passw0rd-12');
        app(TenantProvisioner::class)->addMember($this->alpha, $loner, 'user');
        $lonerProfile = $this->alpha->run(fn () => User::where('central_user_id', $loner->id)->value('id'));
        $this->as('admin@alpha.test', 'alpha')->deleteJson("/api/v1/users/{$lonerProfile}")->assertNoContent();
        $this->assertNull(AccountUser::find($loner->id));
    }

    public function test_suspending_someone_here_does_not_lock_them_out_of_other_companies(): void
    {
        $person = $this->identity('admin@beta.test');
        app(TenantProvisioner::class)->addMember($this->alpha, $person, 'user');
        $profileId = $this->alpha->run(fn () => User::where('central_user_id', $person->id)->value('id'));

        $this->as('admin@alpha.test', 'alpha')->patchJson("/api/v1/users/{$profileId}", ['status' => 'suspended'])->assertOk();

        $this->postJson('/api/v1/auth/token', ['email' => 'admin@beta.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'alpha'])->assertForbidden();
        $this->postJson('/api/v1/auth/token', ['email' => 'admin@beta.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'beta'])->assertOk();
    }

    public function test_each_company_lists_only_its_own_people_and_settings(): void
    {
        $alphaApi = $this->as('admin@alpha.test', 'alpha');
        $alphaApi->putJson('/api/v1/settings', ['name' => 'Alpha Ltd', 'tagline' => 'Alpha says hi'])->assertOk();
        $names = collect($alphaApi->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('email');
        $this->assertSame(['admin@alpha.test'], $names->all());

        $betaApi = $this->as('admin@beta.test', 'beta');
        $betaApi->putJson('/api/v1/settings', ['name' => 'Beta Inc'])->assertOk();
        $this->assertSame(['admin@beta.test'], collect($betaApi->getJson('/api/v1/users')->json('data'))->pluck('email')->all());

        $this->assertSame('Alpha Ltd', $this->as('admin@alpha.test', 'alpha')->getJson('/api/v1/settings')->json('data.name'));
        $this->assertSame('Beta Inc', $this->as('admin@beta.test', 'beta')->getJson('/api/v1/settings')->json('data.name'));
        $this->assertSame('Alpha says hi', $this->as('admin@alpha.test', 'alpha')->getJson('/api/v1/settings')->json('data.tagline'));
        $this->assertSame('Build. Connect. Automate.', $this->as('admin@beta.test', 'beta')->getJson('/api/v1/settings')->json('data.tagline'), 'a company that set nothing gets the defaults');
    }
}
