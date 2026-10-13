<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

class WebhookDispatcher
{
    /** Queue a delivery to one endpoint. */
    public function send(int $endpointId, string $event, array $payload): void
    {
        $endpoint = WebhookEndpoint::where('direction', 'out')->where('is_active', true)->find($endpointId);
        if ($endpoint) {
            $this->queue($endpoint, $event, $payload);
        }
    }

    /** Fan an event out to every active outbound endpoint subscribed to it. */
    public function broadcast(string $event, array $payload): void
    {
        WebhookEndpoint::where('direction', 'out')->where('is_active', true)->get()
            ->filter(fn ($e) => empty($e->events) || in_array($event, $e->events, true) || in_array('*', $e->events, true))
            ->each(fn ($e) => $this->queue($e, $event, $payload));
    }

    private function queue(WebhookEndpoint $endpoint, string $event, array $payload): void
    {
        $delivery = WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'payload' => $payload]);
        DeliverWebhook::dispatch($delivery->id);
    }
}
