<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public array $backoff = [30, 120, 600, 3600, 14400];

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('webhookEndpoint')->findOrFail($this->deliveryId);
        $endpoint = $delivery->webhookEndpoint;
        $body = json_encode(['event' => $delivery->event, 'data' => $delivery->payload, 'id' => $delivery->id]);

        $response = Http::withHeaders([
            'X-Orvyn-Event' => $delivery->event,
            'X-Orvyn-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $endpoint->secret),
        ])->withBody($body, 'application/json')->timeout(10)->post($endpoint->url);

        $delivery->increment('attempts');
        $delivery->update(['response_status' => $response->status(), 'delivered_at' => $response->successful() ? now() : null]);

        $response->throw();
    }
}
