<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataObject;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Email templates (+ groups) and the merge-field catalogue the composers offer. */
class EmailTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = EmailTemplate::orderBy('group')->orderBy('name');
        if ($g = $request->query('group')) {
            $q->where('group', $g);
        }
        $light = $request->boolean('light');   // lists for pickers skip the heavy html

        return response()->json([
            'data' => $q->get($light ? ['id', 'name', 'group', 'subject'] : ['*']),
            'groups' => EmailTemplate::whereNotNull('group')->distinct()->orderBy('group')->pluck('group'),
        ]);
    }

    public function show(EmailTemplate $emailTemplate): JsonResponse
    {
        return response()->json(['data' => $emailTemplate]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => EmailTemplate::create($this->validated($request))], 201);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $emailTemplate->update($this->validated($request));

        return response()->json(['data' => $emailTemplate]);
    }

    public function duplicate(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermission('templates.edit') || $request->user()->isAdmin(), 403);

        return response()->json(['data' => EmailTemplate::create($emailTemplate->only(['group', 'subject', 'html', 'design']) + ['name' => $emailTemplate->name.' (copy)'])], 201);
    }

    public function destroy(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermission('templates.edit') || $request->user()->isAdmin(), 403);
        $emailTemplate->delete();

        return response()->json(null, 204);
    }

    /** Merge fields offered in composers: contact + sender + (per object) record fields. */
    public function mergeFields(): JsonResponse
    {
        $groups = [
            ['group' => 'Contact', 'fields' => collect(['first_name', 'last_name', 'full_name', 'email', 'phone', 'company_name', 'job_title'])
                ->map(fn ($k) => ['label' => ucwords(str_replace('_', ' ', $k)), 'token' => '{{contact.'.$k.'}}'])->all()],
            ['group' => 'App', 'fields' => [['label' => 'App name', 'token' => '{{app.name}}'], ['label' => 'App URL', 'token' => '{{app.url}}']]],
        ];
        foreach (DataObject::with('fields:id,data_object_id,key,label')->get() as $o) {
            $groups[] = ['group' => $o->name, 'fields' => $o->fields->map(fn ($f) => ['label' => $f->label, 'token' => '{{record.'.$f->key.'}}'])->all()];
        }

        return response()->json(['data' => $groups]);
    }

    private function validated(Request $request): array
    {
        abort_unless($request->user()->hasPermission('templates.edit') || $request->user()->isAdmin(), 403);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'], 'group' => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:255'], 'html' => ['required', 'string', 'max:500000'], 'design' => ['nullable', 'array'],
        ]);
    }
}
