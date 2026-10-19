<?php

namespace App\Http\Controllers;

use App\Models\EmailMessage;
use App\Models\Setting;
use App\Services\Messaging\Unsubscribes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page behind the unsubscribe link in e-mails (GET /u/{tracker}): no sign-in, the message's tracking id says who and which company.
 * POST unsubscribes (it is also what a mail program sends for one-click unsubscribe, RFC 8058), and POST .../resubscribe undoes it.
 */
class UnsubscribeController extends Controller
{
    public function __construct(private readonly Unsubscribes $unsubscribes) {}

    public function show(string $tracker): Response
    {
        $message = $this->message($tracker);

        return $this->page($message, $message->contact->email_opt_out ? 'done' : 'confirm');
    }

    public function confirm(Request $request, string $tracker): Response
    {
        $message = $this->message($tracker);
        $this->unsubscribes->optOut($message->contact, $message);

        // a mail program's one-click request gets a plain answer; a person who pressed the button gets the page
        return $request->has('List-Unsubscribe') ? response('Unsubscribed.', 200) : $this->page($message->fresh('contact'), 'done');
    }

    public function resubscribe(string $tracker): Response
    {
        $message = $this->message($tracker);
        $this->unsubscribes->optIn($message->contact, $message);

        return $this->page($message->fresh('contact'), 'back');
    }

    private function message(string $tracker): EmailMessage
    {
        return EmailMessage::where('tracker_id', $tracker)->with('contact')->firstOrFail();
    }

    private function page(EmailMessage $message, string $state): Response
    {
        $contact = $message->contact ?? abort(404);

        return response()->view('unsubscribe', [
            'state' => $state, 'company' => (string) (Setting::get('app.name') ?: tenant('name') ?: config('app.name')),
            'email' => (string) $contact->email, 'url' => url('/u/'.$message->tracker_id),
        ])->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'")->header('Referrer-Policy', 'no-referrer');
    }
}
