<?php

namespace App\Http\Middleware;

use App\Models\PublicLink;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For requests with no session (embedded forms, shared price lists, tracking pixels, provider webhooks): the key in
 * the URL identifies the company. Usage: ->middleware('tenant.public:form') | 'price_list' | 'webhook' | 'tracker'.
 *
 * Tracking ids are "<company id><ulid>": the company id is the first 12 characters, so no lookup table is needed
 * for the (very numerous) emails.
 */
class ResolvePublicTenant
{
    public const TRACKER_PREFIX = 12;

    public function handle(Request $request, Closure $next, string $type): Response
    {
        if (config('app.single_database')) {
            return $next($request);       // one database: nothing to look up
        }

        // The key in the URL decides, never the visitor's session: a signed-in person opening a form link or an
        // email's tracking pixel from another company must still reach the company that owns the link.
        $tenant = $this->tenantFor($request, $type);
        abort_unless($tenant && $tenant->isActive(), 404);
        if (! tenancy()->initialized || tenant('id') !== $tenant->getTenantKey()) {
            tenancy()->initialized && tenancy()->end();
            tenancy()->initialize($tenant);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    private function tenantFor(Request $request, string $type): ?Tenant
    {
        $route = $request->route();

        return match ($type) {
            'tracker' => ($id = substr((string) $route?->parameter('tracker'), 0, self::TRACKER_PREFIX)) !== '' ? Tenant::find($id) : null,
            'webhook' => ($t = (string) ($request->query('token') ?: $request->header('X-Webhook-Token'))) !== '' ? PublicLink::resolve(PublicLink::WEBHOOK, hash('sha256', $t)) : null,
            'form' => PublicLink::resolve(PublicLink::FORM, (string) $route?->parameter('uuid')),
            'price_list' => PublicLink::resolve(PublicLink::PRICE_LIST, (string) $route?->parameter('token')),
            default => null,
        };
    }
}
