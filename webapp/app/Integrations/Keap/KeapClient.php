<?php

namespace App\Integrations\Keap;

use App\Models\Integration;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Keap / Infusionsoft REST v1 client. Replaces application/libraries/InfusionsoftSDK.
 * Pagination is exposed as a generator so syncing millions of contacts never loads them all in memory.
 */
class KeapClient
{
    private const BASE = 'https://api.infusionsoft.com/crm/rest/v1';

    private function http(): PendingRequest
    {
        $integration = Integration::for('keap') ?? throw new RuntimeException('Keap integration is not enabled.');

        $c = (array) $integration->credentials;
        $http = Http::baseUrl(self::BASE);
        // the company's service account key or personal access token (Company settings, Keap); an OAuth access token from an old import still works
        $http = ! empty($c['api_key']) ? $http->withHeaders(['X-Keap-API-Key' => $c['api_key']]) : $http->withToken($c['access_token'] ?? '');

        return $http->acceptJson()->timeout(30)
            ->retry(3, 1000, fn ($e) => in_array($e->response?->status(), [429, 500, 502, 503], true), throw: false);
    }

    /** @return Generator<int, array> pages of contacts updated since $since */
    public function contacts(?string $since = null, int $pageSize = 200): Generator
    {
        $url = '/contacts';
        $query = array_filter(['limit' => $pageSize, 'order' => 'id', 'since' => $since, 'optional_properties' => 'custom_fields,tag_ids']);

        while ($url) {
            $response = $this->http()->get($url, $query)->throw();
            yield $response->json('contacts', []);

            $next = $response->json('next');
            $url = $next ? $next : null;
            $query = [];   // the "next" URL already carries the cursor
        }
    }

    public function updateContact(int $id, array $payload): array
    {
        return $this->http()->patch("/contacts/$id", $payload)->throw()->json();
    }

    /** Is the key accepted? Throws with Keap's own answer when it is not. */
    public function ping(): void
    {
        $this->http()->get('/tags', ['limit' => 1])->throw();
    }

    public function tags(): array
    {
        return $this->http()->get('/tags', ['limit' => 1000])->throw()->json('tags', []);
    }
}
