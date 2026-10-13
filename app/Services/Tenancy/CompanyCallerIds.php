<?php

namespace App\Services\Tenancy;

use App\Integrations\Twilio\CallerIds;
use App\Integrations\Twilio\TwilioClient;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Which numbers a company may pick as a user's caller ID. A company with its own Twilio account sees (and manages) that
 * account's numbers. Any other company sees only what the platform offers from its shared account, and never manages it.
 */
class CompanyCallerIds
{
    public function __construct(private TwilioClient $twilio, private CallerIds $callerIds, private CallSettings $calls) {}

    /**
     * @return array{source: 'company'|'platform'|null, can_manage: bool, numbers: list<array{sid: string, number: string, label: string, kind: string}>, error: ?string}
     */
    public function overview(): array
    {
        $account = $this->twilio->account();
        if (! $account) {
            return ['source' => null, 'can_manage' => false, 'numbers' => [], 'error' => null];
        }

        try {
            $numbers = $this->callerIds->numbers($account);
        } catch (Throwable $e) {
            return ['source' => $account['source'], 'can_manage' => false, 'numbers' => [], 'error' => str($e->getMessage())->squish()->limit(200)->value()];
        }
        if ($account['source'] === 'platform') {
            $offered = $this->calls->offeredNumbers();
            $numbers = array_values(array_filter($numbers, fn ($n) => in_array($n['number'], $offered, true)));
        }

        return ['source' => $account['source'], 'can_manage' => $account['source'] === 'company', 'numbers' => $numbers, 'error' => null];
    }

    /**
     * The company's own account, only when it may manage it (verify, rename, remove).
     *
     * @return array{sid: string, token: string}|null
     */
    public function managedAccount(): ?array
    {
        $account = $this->twilio->account();

        return $account && $account['source'] === 'company' ? $account : null;
    }

    /**
     * A caller ID must be a number the account can really show, or Twilio refuses the call. When the list cannot be known
     * (no Twilio account, or Twilio unreachable) any number is accepted. Returns what to store: the number with its spaces
     * and brackets removed (+15551234567), or as typed when it is not a number Twilio knows.
     *
     * @throws ValidationException
     */
    public function assertUsable(?string $number): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }
        $overview = $this->overview();
        if ($overview['source'] === null || $overview['error'] !== null) {
            return $number;
        }
        $wanted = CallerIds::normalise($number);
        $allowed = array_map(fn ($n) => $n['number'], $overview['numbers']);
        $default = CallerIds::normalise($this->calls->defaultCallerId());
        $default !== null && $allowed[] = $default;

        if ($wanted === null || ! in_array($wanted, $allowed, true)) {
            throw ValidationException::withMessages(['caller_id' => 'Pick one of the numbers your Twilio account can call from. Verify a number first if it is not in the list.']);
        }

        return $wanted;
    }
}
