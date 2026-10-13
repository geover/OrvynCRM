<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin Form Builder. Public rendering/submission lives in PublicFormController. */
class FormController extends Controller
{
    public const CONTACT_COLUMNS = ['first_name', 'middle_name', 'last_name', 'title', 'email', 'phone', 'company_name', 'job_title', 'website'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => Form::withCount('submissions')->with('dataObject:id,name')->orderBy('name')->get()->map(fn ($f) => $f->toArray() + [
            'public_url' => url("/f/{$f->uuid}"), 'embed_script' => '<script src="'.url("/f/{$f->uuid}.js").'" async></script>',
            'embed_iframe' => '<iframe src="'.url("/f/{$f->uuid}").'" style="width:100%;border:0;min-height:480px" title="'.e($f->name).'"></iframe>',
        ])]);
    }

    public function show(Form $form): JsonResponse
    {
        return response()->json(['data' => $form]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Form::create($this->validated($request))], 201);
    }

    public function update(Request $request, Form $form): JsonResponse
    {
        $form->update($this->validated($request));

        return response()->json(['data' => $form]);
    }

    public function destroy(Request $request, Form $form): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $form->delete();

        return response()->json(null, 204);
    }

    public function submissions(Request $request, Form $form): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json($form->submissions()->latest('id')->cursorPaginate(30));
    }

    private function validated(Request $request): array
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'data_object_id' => ['nullable', 'exists:data_objects,id'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'schema' => ['required', 'array'],
            'schema.columns' => ['nullable', 'integer', 'between:1,3'],
            'schema.fields' => ['required', 'array', 'min:1', 'max:60'],
            'schema.fields.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'schema.fields.*.label' => ['required', 'string', 'max:255'],
            'schema.fields.*.type' => ['required', Rule::in(['text', 'textarea', 'email', 'number', 'date', 'select', 'checkbox', 'phone', 'url'])],
            'schema.fields.*.required' => ['boolean'],
            'schema.fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'schema.fields.*.tooltip' => ['nullable', 'string', 'max:255'],
            'schema.fields.*.options' => ['nullable', 'array', 'max:100'], 'schema.fields.*.options.*' => ['string', 'max:255'],
            'schema.fields.*.map_to' => ['nullable', 'string', 'max:120'],     // contact.<column> | record.<field key>
            'schema.fields.*.group' => ['nullable', 'string', 'max:120'],
            'settings' => ['nullable', 'array'],
            'settings.submit_label' => ['nullable', 'string', 'max:60'], 'settings.thank_you' => ['nullable', 'string', 'max:1000'],
            'settings.redirect_url' => ['nullable', 'url:http,https'], 'settings.relationship_id' => ['nullable', 'exists:data_object_relationships,id'],
            'settings.notify_emails' => ['nullable', 'array', 'max:5'], 'settings.notify_emails.*' => ['email'],
        ]);

        foreach ($d['schema']['fields'] as $f) {
            $m = $f['map_to'] ?? null;
            if ($m && str_starts_with($m, 'contact.') && ! in_array(substr($m, 8), self::CONTACT_COLUMNS, true)) {
                abort(422, "Field “{$f['label']}” maps to an unknown contact field.");
            }
        }

        return $d;
    }
}
