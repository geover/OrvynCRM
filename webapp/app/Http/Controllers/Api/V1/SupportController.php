<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailMessage;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Services\Tenancy\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Contact support": e-mails the configured internal support addresses on behalf of the user. */
class SupportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->validate(['subject' => ['required', 'string', 'max:200'], 'message' => ['required', 'string', 'max:5000'], 'contact_id' => ['nullable', 'exists:contacts,id']]);
        $to = Setting::get('support.internal_emails', []);
        abort_if(! $to, 422, 'No internal support emails are configured. Ask an administrator to add one under Admin settings.');

        $u = $request->user();
        $body = '<p><b>From:</b> '.e($u->name).' &lt;'.e($u->email).'&gt;</p>'
            .(isset($d['contact_id']) ? '<p><b>Contact:</b> <a href="'.e(url("/contacts/{$d['contact_id']}")).'">'.e(url("/contacts/{$d['contact_id']}")).'</a></p>' : '')
            .'<p>'.nl2br(e($d['message'])).'</p>';

        foreach ($to as $address) {
            $m = EmailMessage::create([
                'user_id' => $u->id, 'subject' => '[Support] '.$d['subject'], 'html' => $body, 'from_email' => app(MailSettings::class)->from()['address'],
                'from_name' => app(MailSettings::class)->from()['name'], 'to' => [['email' => $address]], 'cc' => [['email' => $u->email]],
            ]);
            SendEmailMessage::dispatch($m->id);
        }

        return response()->json(['sent' => count($to)], 202);
    }
}
