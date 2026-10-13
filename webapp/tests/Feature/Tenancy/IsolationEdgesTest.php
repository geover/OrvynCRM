<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Services\Tenancy\CompanySession;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TenantTestCase;

/** The less obvious ways one company could touch another: shared locks and limits, stale sign-ins, the company cookie. */
class IsolationEdgesTest extends TenantTestCase
{
    public function test_locks_taken_from_the_container_are_per_company(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');

        // queue middleware such as WithoutOverlapping asks the container for the cache repository
        $lockA = $a->run(function () {
            $lock = app(Repository::class)->lock('automation-1-all', 60);
            $this->assertTrue($lock->get());

            return $lock;
        });

        $b->run(function () {
            $lock = app(Repository::class)->lock('automation-1-all', 60);
            $this->assertTrue($lock->get(), "Beta's evaluation of automation #1 must not wait for Alpha's");
            $lock->release();
        });

        $a->run(function () {
            $this->assertFalse(app(Repository::class)->lock('automation-1-all', 60)->get(), 'but a second pass in Alpha itself is still refused');
        });
        $lockA->forceRelease();
    }

    public function test_rate_limit_buckets_are_per_company(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $request = Request::create('/api/v1/contacts');                      // anonymous: keyed by IP, which is the same everywhere
        $key = fn ($company) => $company->run(fn () => RateLimiter::limiter('api')($request)->key);

        $this->assertNotSame($key($a), $key($b));
        $this->assertStringStartsWith($a->id, $key($a));

        $mail = fn ($company) => $company->run(fn () => RateLimiter::limiter('outbound-email')($request)->key);
        $this->assertNotSame($mail($a), $mail($b), "one company's email blast must not use up another's sending quota");
    }

    public function test_a_session_pointing_at_a_deleted_company_is_signed_out_not_an_error(): void
    {
        $gone = $this->company('Gone', 'g@gone.test');
        $profileId = $gone->run(fn () => User::firstOrFail()->id);
        $signedIn = ['tenant_id' => $gone->id, auth()->guard('web')->getName() => $profileId];
        $gone->delete();

        $this->withSession($signedIn)->get('/')->assertRedirect('/login');
        $this->assertNull(session('tenant_id'));
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_the_company_cookie_finds_the_company_when_the_session_has_lost_it(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');

        $recaller = auth()->guard('web')->getRecallerName();

        $this->withCookie(CompanySession::COOKIE, $a->id)->withCookie($recaller, '1|token|hash')->get('/login');
        $this->assertSame($a->id, session('tenant_id'), 'remembered, so the session guard can check the remember-me token in the right company');
    }

    public function test_the_company_cookie_alone_never_opens_a_company(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');

        // a request still in flight when someone signs out carries the old company cookie but no remember-me cookie
        $this->withCookie(CompanySession::COOKIE, $a->id)->get('/login')->assertOk();
        $this->assertNull(session('tenant_id'));
    }

    public function test_a_cookie_naming_no_company_is_ignored(): void
    {
        $this->company('Alpha', 'a@alpha.test');

        $this->withCookie(CompanySession::COOKIE, 'nosuchcompany')->get('/login')->assertOk();
        $this->assertNull(session('tenant_id'));
        $this->withCookie(CompanySession::COOKIE, "x' OR 1=1 --")->get('/login')->assertOk();
    }

    public function test_a_leftover_remember_me_cookie_without_a_company_is_dropped_not_looked_up_in_the_central_database(): void
    {
        $this->company('Alpha', 'a@alpha.test');
        $this->flushSession();
        $this->disableCookieEncryption();                                               // hand-made cookies, as an old browser would send them
        $recaller = auth()->guard('web')->getRecallerName();

        // no company cookie: the company cannot be found, and the guard must not query company users centrally
        $response = $this->withUnencryptedCookie($recaller, '1|a-token-from-before|a-hash')->get('/contacts');

        $response->assertRedirect('/login');
        $response->assertCookieExpired($recaller);                                      // the browser is told to delete it
    }
}
