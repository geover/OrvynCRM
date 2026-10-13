<?php

namespace App\Http\Controllers;

use App\Integrations\Twilio\CallerIds;
use App\Services\Tenancy\CallSettings;
use App\Services\Tenancy\SmsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Platform > Settings > SMS and Calls: the integrations every company can use. (E-mail has its own page: PlatformSettingsController.) */
class PlatformIntegrationController extends Controller
{
    public function sms(SmsSettings $sms): Response
    {
        return Inertia::render('Platform/SettingsSms', ['sms' => $sms->current(), 'ready' => $sms->account() !== null]);
    }

    public function saveSms(Request $request, SmsSettings $sms): RedirectResponse
    {
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'account_sid' => ['nullable', 'required_if:enabled,true', 'string', 'max:80'],
            'auth_token' => ['nullable', 'string', 'max:200'],
            'clear_token' => ['sometimes', 'boolean'],
            'from_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-]{6,20}$/'],
            'messaging_service_sid' => ['nullable', 'string', 'max:80'],
        ], ['from_number.regex' => 'Enter a phone number such as +15551234567.']);
        $sms->save($d);

        return back()->with('success', $d['enabled'] ? 'Saved. Companies without their own Twilio account now send through this one.' : 'Saved. Text messages are off for companies without their own Twilio account.');
    }

    /** Send one text through the saved account and show Twilio's own error when it fails. */
    public function testSms(Request $request, SmsSettings $sms): RedirectResponse
    {
        $d = $request->validate(['to' => ['required', 'string', 'regex:/^\+?[0-9 ()\-]{6,20}$/']], ['to.regex' => 'Enter a phone number such as +15551234567.']);
        try {
            $sms->sendTest($d['to']);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['test' => str($e->getMessage())->squish()->limit(300)->value()]);
        }

        return back()->with('success', "Test message sent to {$d['to']}.");
    }

    public function calls(CallSettings $calls, SmsSettings $sms, CallerIds $callerIds): Response
    {
        $numbers = null;
        $error = null;
        if ($account = $sms->account()) {
            try {
                $numbers = $callerIds->numbers($account);
            } catch (Throwable $e) {
                $error = str($e->getMessage())->squish()->limit(200)->value();
            }
        }

        return Inertia::render('Platform/SettingsCalls', [
            'calls' => $calls->current() + ['offered' => $calls->offeredNumbers()],
            'numbers' => $numbers, 'numbers_error' => $error, 'account_ready' => $account !== null,
            'verification' => session('caller_verification'),
        ]);
    }

    /** Which of the platform account's numbers companies without their own Twilio account may choose from. */
    public function saveOfferedNumbers(Request $request, CallSettings $calls, SmsSettings $sms, CallerIds $callerIds): RedirectResponse
    {
        $d = $request->validate(['numbers' => ['present', 'array', 'max:200'], 'numbers.*' => ['string', 'max:30']]);
        $known = collect($this->account($sms) ? $callerIds->numbers($this->account($sms)) : [])->pluck('number')->all();
        $calls->saveOfferedNumbers(array_values(array_intersect($d['numbers'], $known)));

        return back()->with('success', 'Saved. Companies can choose from the numbers you ticked.');
    }

    /** Twilio calls the number; the code to type is shown on the page, which then waits for the result. */
    public function verifyNumber(Request $request, SmsSettings $sms, CallerIds $callerIds): RedirectResponse
    {
        $d = $request->validate(['number' => ['required', 'string', 'max:30'], 'label' => ['nullable', 'string', 'max:64']]);
        try {
            $started = $callerIds->startVerification($this->account($sms) ?? throw new \RuntimeException('Set up and switch on the Twilio account first (Settings, SMS).'), $d['number'], (string) ($d['label'] ?? ''));
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['number' => str($e->getMessage())->squish()->limit(300)->value()]);
        }

        return back()->with('caller_verification', $started);
    }

    public function numberStatus(Request $request, SmsSettings $sms, CallerIds $callerIds): JsonResponse
    {
        $number = (string) $request->query('number');
        try {
            return response()->json(['verified' => ($account = $this->account($sms)) !== null && $callerIds->isVerified($account, $number)]);
        } catch (Throwable) {
            return response()->json(['verified' => false]);
        }
    }

    public function renameNumber(Request $request, string $sid, SmsSettings $sms, CallerIds $callerIds): RedirectResponse
    {
        $d = $request->validate(['label' => ['required', 'string', 'max:64']]);

        return $this->change(fn () => $callerIds->rename($this->account($sms) ?? throw new \RuntimeException('No Twilio account.'), $sid, $d['label']), 'Name saved.');
    }

    /** Removes a verified number from the Twilio account (a number the account owns cannot be removed here). */
    public function removeNumber(string $sid, SmsSettings $sms, CallerIds $callerIds, CallSettings $calls): RedirectResponse
    {
        return $this->change(function () use ($sid, $sms, $callerIds, $calls) {
            $account = $this->account($sms) ?? throw new \RuntimeException('No Twilio account.');
            $number = collect($callerIds->numbers($account))->firstWhere('sid', $sid);
            $callerIds->remove($account, $sid);
            $number && $calls->saveOfferedNumbers(array_values(array_diff($calls->offeredNumbers(), [$number['number']])));
        }, 'Number removed from the Twilio account.');
    }

    /** @return array{sid: string, token: string}|null */
    private function account(SmsSettings $sms): ?array
    {
        return $sms->account();
    }

    private function change(callable $do, string $message): RedirectResponse
    {
        try {
            $do();
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['numbers' => str($e->getMessage())->squish()->limit(300)->value()]);
        }

        return back()->with('success', $message);
    }

    public function saveCalls(Request $request, CallSettings $calls): RedirectResponse
    {
        $d = $request->validate([
            'default_caller_id' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()\-]{6,20}$/'],
            'company_caller_ids' => ['required', 'boolean'],
        ], ['default_caller_id.regex' => 'Enter a phone number such as +15551234567.']);
        $calls->save($d);

        return back()->with('success', 'Calls settings saved.');
    }
}
