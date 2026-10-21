<?php

namespace App\Services\Messaging;

use App\Integrations\Twilio\TwilioClient;
use App\Services\Tenancy\CompanyServices;
use App\Services\Tenancy\MailSettings;

/**
 * Can this company's e-mails and texts actually leave the system? An automation's "Send email" or "Send SMS" action is
 * accepted either way, but nothing is delivered without a mail server, a Twilio account, and the platform's sending switch.
 */
class MessagingReadiness
{
    public function __construct(private MailSettings $mail, private TwilioClient $twilio, private CompanyServices $services) {}

    /** @return array{email: array{ready: bool, reason: ?string}, sms: array{ready: bool, reason: ?string}} */
    public function status(): array
    {
        $paused = ! $this->services->enabled('messages') ? 'The platform has paused e-mail and SMS sending for your company, so nothing is sent until it is switched back on.' : null;

        return ['email' => $this->channel($paused ?? $this->emailProblem()), 'sms' => $this->channel($paused ?? $this->smsProblem())];
    }

    private function emailProblem(): ?string
    {
        $this->mail->apply();

        return in_array((string) config('mail.default'), ['log', 'array', 'null'], true)
            ? 'No e-mail server is set up, so e-mails are not sent. Ask your platform administrator to set one up (Platform, Settings, E-mail server).'
            : null;
    }

    private function smsProblem(): ?string
    {
        return $this->twilio->available()
            ? null
            : 'No Twilio account is available, so text messages are not sent. Add your own under Company settings, Twilio, or ask your platform administrator to switch on the platform\'s.';
    }

    /** @return array{ready: bool, reason: ?string} */
    private function channel(?string $problem): array
    {
        return ['ready' => $problem === null, 'reason' => $problem];
    }
}
