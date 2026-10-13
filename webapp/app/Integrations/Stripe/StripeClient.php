<?php

namespace App\Integrations\Stripe;

use App\Services\Tenancy\PaymentSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin client for Stripe's REST API (no SDK needed, like the Twilio client). Card details never touch this application:
 * people type them on Stripe's own pages (Checkout and the Customer Portal) and Stripe tells us the outcome by webhook.
 */
class StripeClient
{
    /** Pinned so the shape of Stripe's answers does not change under us. */
    private const VERSION = '2024-06-20';

    public function __construct(private readonly PaymentSettings $settings) {}

    public function configured(): bool
    {
        return $this->settings->secretKey() !== null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws RuntimeException with Stripe's own message when it refuses
     */
    public function request(string $method, string $path, array $params = []): array
    {
        $key = $this->settings->secretKey() ?? throw new RuntimeException('Stripe is not set up. A platform administrator enters the keys under Settings, Payments.');

        $http = Http::withToken($key)->withHeaders(['Stripe-Version' => self::VERSION])->timeout(20)->acceptJson();
        $url = 'https://api.stripe.com/v1/'.ltrim($path, '/');
        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $params),
            'DELETE' => $http->asForm()->delete($url, $params),
            default => $http->asForm()->post($url, $params),
        };

        if ($response->failed()) {
            throw new RuntimeException('Stripe: '.($response->json('error.message') ?? 'error '.$response->status()));
        }

        return (array) $response->json();
    }

    /** @param  array<string, mixed>  $params @return array<string, mixed> */
    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    /** @param  array<string, mixed>  $params @return array<string, mixed> */
    public function post(string $path, array $params = []): array
    {
        return $this->request('POST', $path, $params);
    }

    /** Is the webhook body really from Stripe? (`Stripe-Signature: t=…,v1=…`, signed with the endpoint's signing secret.) */
    public function validSignature(string $payload, ?string $header, int $toleranceSeconds = 300): bool
    {
        $secret = $this->settings->webhookSecret();
        if (! $secret || ! $header) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
