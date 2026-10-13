<?php

namespace App\Integrations\Twilio;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The numbers a Twilio account may show as the caller ID: numbers it owns, and numbers it has verified (Twilio calls the
 * number and the person types a code). Works on any account array from TwilioClient::account() or SmsSettings::account().
 */
class CallerIds
{
    private const BASE = 'https://api.twilio.com/2010-04-01/Accounts/';

    /**
     * @param  array{sid: string, token: string}  $account
     * @return list<array{sid: string, number: string, label: string, kind: 'owned'|'verified'}> sorted by label
     */
    public function numbers(array $account): array
    {
        return Cache::remember($this->cacheKey($account), 120, function () use ($account) {
            $owned = $this->list($account, 'IncomingPhoneNumbers', 'incoming_phone_numbers', 'owned');
            $verified = $this->list($account, 'OutgoingCallerIds', 'outgoing_caller_ids', 'verified');
            $numbers = array_values(collect($owned)->merge($verified)->unique('number')->sortBy(fn ($n) => strtolower($n['label']))->all());

            return $numbers;
        });
    }

    /**
     * Ask Twilio to call the number; the person answers and types the returned code on the keypad. Poll isVerified() after.
     *
     * @param  array{sid: string, token: string}  $account
     * @return array{code: string, number: string}
     */
    public function startVerification(array $account, string $number, string $label): array
    {
        $number = self::normalise($number) ?? throw new RuntimeException('Enter the number with its country code, for example +15551234567.');
        $response = $this->http($account)->asForm()->post($this->url($account, 'OutgoingCallerIds'), ['PhoneNumber' => $number, 'FriendlyName' => $label !== '' ? $label : $number]);
        if ($response->failed()) {
            throw new RuntimeException($response->json('code') === 21450 ? 'That number is already verified.' : 'Twilio could not start the verification: '.($response->json('message') ?? $response->status()));
        }

        return ['code' => (string) $response->json('validation_code'), 'number' => $number];
    }

    /** @param  array{sid: string, token: string}  $account */
    public function isVerified(array $account, string $number): bool
    {
        Cache::forget($this->cacheKey($account));
        $number = self::normalise($number);

        return $number !== null && collect($this->numbers($account))->contains(fn ($n) => $n['number'] === $number);
    }

    /** @param  array{sid: string, token: string}  $account */
    public function rename(array $account, string $sid, string $label): void
    {
        $this->check($this->http($account)->asForm()->post($this->url($account, "OutgoingCallerIds/{$sid}"), ['FriendlyName' => $label]));
        Cache::forget($this->cacheKey($account));
    }

    /** @param  array{sid: string, token: string}  $account */
    public function remove(array $account, string $sid): void
    {
        $this->check($this->http($account)->delete($this->url($account, "OutgoingCallerIds/{$sid}")));
        Cache::forget($this->cacheKey($account));
    }

    /** +15551234567 from "+1 (555) 123-4567"; null when it is not a number with a country code. */
    public static function normalise(?string $number): ?string
    {
        $clean = preg_replace('/[\s().\-]/', '', (string) $number);

        return preg_match('/^\+[1-9][0-9]{6,14}$/', $clean) ? $clean : null;
    }

    /** @return list<array{sid: string, number: string, label: string, kind: string}> */
    private function list(array $account, string $path, string $key, string $kind): array
    {
        $response = $this->http($account)->get($this->url($account, $path), ['PageSize' => 1000]);
        $this->check($response);

        return collect($response->json($key, []))->map(fn ($n) => [
            'sid' => (string) $n['sid'], 'number' => (string) $n['phone_number'], 'kind' => $kind,
            'label' => ($n['friendly_name'] ?? '') !== '' ? (string) $n['friendly_name'] : (string) $n['phone_number'],
        ])->all();
    }

    private function http(array $account): PendingRequest
    {
        return Http::withBasicAuth($account['sid'], $account['token'])->timeout(15)->retry(2, 500, throw: false);
    }

    private function url(array $account, string $path): string
    {
        return self::BASE.$account['sid'].'/'.$path.'.json';
    }

    private function check($response): void
    {
        if ($response->failed()) {
            throw new RuntimeException('Twilio error: '.($response->json('message') ?? $response->status()));
        }
    }

    private function cacheKey(array $account): string
    {
        return 'twilio.caller_ids.'.$account['sid'];
    }
}
