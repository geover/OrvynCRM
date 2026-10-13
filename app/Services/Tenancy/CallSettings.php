<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;
use Illuminate\Validation\ValidationException;

/**
 * What the platform decides about calls. Calls are placed from the person's own phone through call links (tel:); the caller
 * ID is the number a contact sees. The platform can give every user without a caller ID a default one, and can decide that
 * only it (not each company) sets caller IDs.
 */
class CallSettings
{
    /** @return array{default_caller_id: string, company_caller_ids: bool} */
    public function current(): array
    {
        return [
            'default_caller_id' => (string) PlatformSetting::get('calls.default_caller_id'),
            // on unless the platform switched it off
            'company_caller_ids' => PlatformSetting::get('calls.company_caller_ids') !== '0',
        ];
    }

    /** @param  array{default_caller_id?: ?string, company_caller_ids?: bool}  $d */
    public function save(array $d): void
    {
        PlatformSetting::put('calls.default_caller_id', ($d['default_caller_id'] ?? null) === null || $d['default_caller_id'] === '' ? null : trim($d['default_caller_id']));
        PlatformSetting::put('calls.company_caller_ids', ! empty($d['company_caller_ids']) ? '1' : '0');
    }

    /**
     * The platform account's numbers that companies without their own Twilio account may use as a caller ID. A company never
     * sees the other numbers of the shared account.
     *
     * @return list<string>
     */
    public function offeredNumbers(): array
    {
        $list = json_decode((string) PlatformSetting::get('calls.offered_numbers'), true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** @param  list<string>  $numbers */
    public function saveOfferedNumbers(array $numbers): void
    {
        PlatformSetting::put('calls.offered_numbers', json_encode(array_values(array_unique($numbers))));
    }

    public function defaultCallerId(): ?string
    {
        return $this->current()['default_caller_id'] ?: null;
    }

    public function companiesMaySetCallerIds(): bool
    {
        return $this->current()['company_caller_ids'];
    }

    /** The number a contact sees for this user: their own, else the platform's default. */
    public function effectiveCallerId(?string $own): ?string
    {
        return $own ?: $this->defaultCallerId();
    }

    /**
     * A company administrator changing a caller ID while the platform keeps that to itself is refused.
     *
     * @throws ValidationException
     */
    public function ensureCompaniesMaySet(): void
    {
        if (! $this->companiesMaySetCallerIds()) {
            throw ValidationException::withMessages(['caller_id' => 'Caller IDs are set by the platform administrator.']);
        }
    }
}
