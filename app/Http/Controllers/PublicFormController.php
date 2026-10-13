<?php

namespace App\Http\Controllers;

use App\Actions\Contacts\SaveContact;
use App\Http\Controllers\Api\V1\FormController;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\EmailMessage;
use App\Models\Form;
use App\Services\DataObjects\RecordService;
use App\Services\Tenancy\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Public hosted forms: GET /f/{uuid} (page), /f/{uuid}.js (embed loader), POST /api/v1/forms/{uuid}/submit. No auth; throttled. */
class PublicFormController extends Controller
{
    public function page(string $uuid)
    {
        $form = Form::where('uuid', $uuid)->where('status', 'active')->firstOrFail();

        return response()->view('forms.public', ['form' => $form])->header('Content-Security-Policy', "default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; frame-ancestors *");
    }

    /** Tiny loader: injects an auto-resizing iframe where the <script> tag sits. */
    public function embed(string $uuid): Response
    {
        $form = Form::where('uuid', $uuid)->where('status', 'active')->firstOrFail();
        $src = url("/f/{$form->uuid}");
        $js = <<<JS
(function(){var s=document.currentScript,f=document.createElement('iframe');f.src="$src";f.style.cssText='width:100%;border:0;min-height:480px';f.title=document.title;
s.parentNode.insertBefore(f,s);window.addEventListener('message',function(e){if(e.source===f.contentWindow&&e.data&&e.data.orvynFormHeight){f.style.height=e.data.orvynFormHeight+'px';}});})();
JS;

        return response($js, 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'public, max-age=300']);
    }

    public function show(string $uuid): JsonResponse
    {
        $form = Form::where('uuid', $uuid)->where('status', 'active')->firstOrFail();

        return response()->json(['data' => ['name' => $form->name, 'schema' => $form->schema, 'settings' => collect($form->settings)->only(['submit_label', 'thank_you', 'redirect_url'])]]);
    }

    public function submit(Request $request, string $uuid, SaveContact $saveContact, RecordService $records): JsonResponse
    {
        $form = Form::where('uuid', $uuid)->where('status', 'active')->firstOrFail();

        // Honeypot: bots fill the hidden field; pretend success and store nothing.
        if ($request->filled('_hp')) {
            return response()->json(['ok' => true], 201);
        }

        $fields = collect($form->schema['fields'] ?? []);
        $rules = $fields->mapWithKeys(fn ($f) => [$f['key'] => array_filter([($f['required'] ?? false) ? 'required' : 'nullable', match ($f['type'] ?? 'text') {
            'email' => 'email', 'number' => 'numeric', 'date' => 'date', 'url' => 'url', 'checkbox' => 'boolean',
            'select' => isset($f['options']) ? Rule::in($f['options']) : 'string', default => 'string',
        }, 'max:5000'])])->all();

        $payload = Validator::make($request->except('_hp'), $rules)->validate();
        $payload = array_intersect_key($payload, $rules);

        // split by mapping: contact.<col> -> contact, record.<key> -> data object record, unmapped contact column names -> contact
        $contactData = [];
        $recordData = [];
        foreach ($fields as $f) {
            $k = $f['key'];
            if (! array_key_exists($k, $payload) || $payload[$k] === null) {
                continue;
            }
            $map = $f['map_to'] ?? (in_array($k, FormController::CONTACT_COLUMNS, true) ? "contact.$k" : "record.$k");
            str_starts_with($map, 'contact.') ? $contactData[substr($map, 8)] = $payload[$k] : $recordData[substr($map, 7)] = $payload[$k];
        }

        $contact = null;
        if (! empty($contactData['email'])) {
            $contact = Contact::firstOrNew(['email' => strtolower($contactData['email'])]);
            $wasNew = ! $contact->exists;
            $contact = $saveContact->handle($contact, array_filter($contactData) + ($wasNew ? ['source' => 'form:'.$form->id] : []));
        }

        $submission = $form->submissions()->create(['contact_id' => $contact?->id, 'payload' => $payload, 'ip' => $request->ip()]);

        if ($form->data_object_id && $contact && $recordData) {
            $object = DataObject::with('fields')->find($form->data_object_id);
            $rel = $form->settings['relationship_id'] ?? $object->relationships()->value('data_object_relationships.id');
            if ($object && $rel) {
                $records->create($object, $recordData, null, [['contact_id' => $contact->id, 'relationship_id' => $rel]]);
            }
        }

        foreach ((array) ($form->settings['notify_emails'] ?? []) as $to) {
            $m = EmailMessage::create([
                'subject' => "New submission: {$form->name}", 'from_email' => app(MailSettings::class)->from()['address'], 'from_name' => app(MailSettings::class)->from()['name'],
                'to' => [['email' => $to]], 'html' => '<p>'.e($form->name).' received a new submission'.($contact ? ' from '.e($contact->full_name) : '').'.</p><pre>'.e(json_encode($payload, JSON_PRETTY_PRINT)).'</pre>',
            ]);
            SendEmailMessage::dispatch($m->id);
        }

        return response()->json(['ok' => true, 'id' => $submission->id, 'thank_you' => $form->settings['thank_you'] ?? 'Thank you!', 'redirect_url' => $form->settings['redirect_url'] ?? null], 201);
    }
}
