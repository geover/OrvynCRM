<?php

namespace App\Services\Tenancy;

use App\Integrations\Twilio\CallerIds;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * The numbers a company sends texts from ("SMS from numbers"). How many it may use depends on its licence tier. The first
 * number is the default; a person can pick another when writing a text. A company that has chosen none sends from the
 * single number saved with its own Twilio account, or the platform's number. Works inside the OPEN company.
 */
class SmsNumbers
{
    private const KEY = 'sms.from_numbers';

    public function __construct(private readonly Plans $plans, private readonly CompanyCallerIds $callerIds) {}

    /** The numbers the company chose, default first. @return list<string> */
    public function chosen(): array
    {
        $list = Setting::get(self::KEY);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** The default number: the first chosen one, or null (the account's own number is used then). */
    public function default(): ?string
    {
        return $this->chosen()[0] ?? null;
    }

    /**
     * Every number a text may be sent from: what was chosen, or else the one number of the account the company sends through.
     *
     * @return list<string>
     */
    public function usable(): array
    {
        $chosen = $this->chosen();
        if ($chosen) {
            return $chosen;
        }
        $own = Integration::for('twilio')?->settings['from_number'] ?? null;

        return $own ? [CallerIds::normalise($own) ?? $own] : [];
    }

    /** Keep only the first `$allowed` chosen numbers (a company moved to a smaller tier). @return int how many were dropped */
    public function trimTo(int $allowed): int
    {
        $chosen = $this->chosen();
        if (count($chosen) <= $allowed) {
            return 0;
        }
        Setting::put(self::KEY, array_slice($chosen, 0, max(0, $allowed)));

        return count($chosen) - max(0, $allowed);
    }

    public function max(): int
    {
        $tenant = Tenant::find(tenant('id'));

        return $tenant ? $this->plans->smsNumbersMax($tenant) : PHP_INT_MAX;
    }

    /**
     * Replace the chosen numbers. At most the tier's number of them, each one the company's Twilio account can send from.
     *
     * @param  list<string>  $numbers
     * @return list<string> what was saved
     *
     * @throws ValidationException
     */
    public function save(array $numbers): array
    {
        $clean = [];
        foreach ($numbers as $n) {
            $normal = CallerIds::normalise((string) $n);
            if ($normal === null) {
                throw ValidationException::withMessages(['numbers' => "\"{$n}\" is not a phone number. Enter one such as +15551234567."]);
            }
            in_array($normal, $clean, true) || $clean[] = $normal;
        }

        $max = $this->max();
        if (count($clean) > $max) {
            throw ValidationException::withMessages(['numbers' => "Your plan allows {$max} texting ".($max === 1 ? 'number' : 'numbers').'. Remove one, or choose a bigger plan.']);
        }

        $overview = $this->callerIds->overview();
        if ($overview['source'] !== null && $overview['error'] === null) {
            $known = array_map(fn ($n) => $n['number'], $overview['numbers']);
            foreach ($clean as $n) {
                if (! in_array($n, $known, true)) {
                    throw ValidationException::withMessages(['numbers' => "{$n} is not a number your Twilio account can send from. Pick one from the list."]);
                }
            }
        }

        Setting::put(self::KEY, $clean);

        return $clean;
    }

    /**
     * Refuse a number a person picked for one text that is not one of the usable ones.
     *
     * @throws ValidationException
     */
    public function assertUsableForText(?string $number): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }
        $normal = CallerIds::normalise($number);
        if ($normal === null || ! in_array($normal, $this->usable(), true)) {
            throw ValidationException::withMessages(['from_number' => 'Send from one of your texting numbers.']);
        }

        return $normal;
    }
}
