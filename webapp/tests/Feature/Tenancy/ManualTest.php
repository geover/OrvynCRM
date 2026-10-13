<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use Tests\TenantTestCase;

/** The two manuals: one for people inside a company, one for platform administrators. */
class ManualTest extends TenantTestCase
{
    public function test_a_company_user_reads_the_company_manual(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1'])->assertRedirect('/');

        $this->get('/manual')->assertOk()->assertInertia(fn ($page) => $page->component('Manual'));
    }

    public function test_the_company_manual_needs_a_signed_in_user(): void
    {
        $this->get('/manual')->assertRedirect('/login');
    }

    public function test_the_platform_manual_is_for_platform_administrators_only(): void
    {
        $this->company('Acme', 'owner@acme.test');
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);

        $this->get('/platform/manual')->assertRedirect('/login');

        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);
        $this->get('/platform/manual')->assertForbidden();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
        $this->get('/platform/manual')->assertOk()->assertInertia(fn ($page) => $page->component('Platform/Manual'));
    }
}
