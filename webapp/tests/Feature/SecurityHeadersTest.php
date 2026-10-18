<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TenantTestCase;

/** The protections every response carries, and which proxies are believed about the visitor's address. */
class SecurityHeadersTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->company('Acme', 'owner@acme.test');                       // with no company at all the app shows a plain landing page
        Vite::useHotFile(storage_path('framework/no-vite-dev-server'));      // a running `npm run dev` leaves public/hot, which switches the policy off
    }

    public function test_every_page_carries_the_basic_protections(): void
    {
        $r = $this->get('/login');

        $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=()', (string) $r->headers->get('Permissions-Policy'));
        $this->assertNull($r->headers->get('Strict-Transport-Security'), 'not over https, not in production');
    }

    public function test_the_policy_only_runs_scripts_that_carry_this_pages_nonce(): void
    {
        $r = $this->get('/forgot-password');
        $csp = (string) $r->headers->get('Content-Security-Policy');

        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertNotEmpty($m[1] ?? null);
        $this->assertStringContainsString("nonce=\"{$m[1]}\"", $r->getContent(), 'the inline script in the page has the same nonce');
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_every_page_gets_its_own_nonce(): void
    {
        $nonce = fn () => preg_match("/'nonce-([^']+)'/", (string) $this->get('/login')->headers->get('Content-Security-Policy'), $m) ? $m[1] : null;

        $this->assertNotSame($nonce(), $nonce());
    }

    public function test_the_policy_can_be_switched_to_report_only_or_off(): void
    {
        config(['security.csp' => 'report']);
        $r = $this->get('/login');
        $this->assertNull($r->headers->get('Content-Security-Policy'));
        $this->assertNotNull($r->headers->get('Content-Security-Policy-Report-Only'));

        config(['security.csp' => 'off']);
        $r = $this->get('/login');
        $this->assertNull($r->headers->get('Content-Security-Policy'));
        $this->assertNull($r->headers->get('Content-Security-Policy-Report-Only'));
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_response_that_sets_its_own_policy_keeps_it(): void
    {
        Route::get('/__own-policy', fn () => response('x')->header('Content-Security-Policy', "default-src 'none'")->header('X-Frame-Options', 'DENY'));

        $this->get('/__own-policy')->assertHeader('Content-Security-Policy', "default-src 'none'")->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_strict_transport_security_only_over_https_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security');

        config(['security.hsts' => false]);
        $this->assertNull($this->get('https://localhost/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_the_forwarded_address_is_believed_only_from_trusted_proxies(): void
    {
        Route::get('/__ip', fn (Request $r) => $r->ip());
        $headers = ['X-Forwarded-For' => '203.0.113.9'];

        config(['security.trusted_proxies' => null]);
        $this->assertNotSame('203.0.113.9', $this->withHeaders($headers)->get('/__ip')->getContent(), 'nobody is trusted by default: a visitor cannot choose their address');

        config(['security.trusted_proxies' => '*']);
        $this->assertSame('203.0.113.9', $this->withHeaders($headers)->get('/__ip')->getContent());

        config(['security.trusted_proxies' => '10.9.9.9']);
        $this->assertNotSame('203.0.113.9', $this->withHeaders($headers)->get('/__ip')->getContent(), 'the caller is not the named proxy');
    }
}
