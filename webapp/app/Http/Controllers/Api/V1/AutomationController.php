<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\EvaluateAutomation;
use App\Models\Automation;
use App\Models\DataObject;
use App\Services\DataObjects\RecordQuery;
use App\Services\Tenancy\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AutomationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Automation::withCount('runs')->with('dataObject:id,name')->orderBy('name')->get()]);
    }

    public function show(Automation $automation): JsonResponse
    {
        return response()->json(['data' => $automation]);
    }

    public function store(Request $request, Limits $limits): JsonResponse
    {
        $data = $this->validated($request);
        $limits->ensureAutomationRoom($data['data_object_id'] ?? null);

        return response()->json(['data' => Automation::create($data)], 201);
    }

    public function update(Request $request, Automation $automation, Limits $limits): JsonResponse
    {
        $data = $this->validated($request, true);
        if (isset($data['data_object_id']) && (int) $data['data_object_id'] !== (int) $automation->data_object_id) {
            $limits->ensureAutomationRoom((int) $data['data_object_id']);          // moving it onto an object counts there
        }
        $automation->update($data);

        return response()->json(['data' => $automation]);
    }

    public function destroy(Request $request, Automation $automation): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $automation->delete();

        return response()->json(null, 204);
    }

    /** Manually trigger an evaluation pass (handy after editing conditions). */
    public function run(Request $request, Automation $automation): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        EvaluateAutomation::dispatch($automation->id);

        return response()->json(['queued' => true], 202);
    }

    /** Recent executions with per-action outcome, for troubleshooting. */
    public function runs(Request $request, Automation $automation): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $automation->runs()->latest('id')->limit(50)->get(['id', 'data_object_record_id', 'status', 'result', 'created_at'])]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        abort_unless($request->user()->isAdmin(), 403);
        $req = $partial ? 'sometimes' : 'required';

        $d = $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'data_object_id' => ['nullable', 'exists:data_objects,id'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'schedule' => ['sometimes', Rule::in(['on_change', 'every_minute', 'hourly', 'daily'])],
            'conditions' => ['sometimes', 'nullable', 'array'],   // empty = every item
            'actions' => [$req, 'array', 'min:1', 'max:20'],
            'actions.*.type' => ['required', Rule::in(['send_email', 'send_sms', 'add_tag', 'remove_tag', 'create_task', 'update_field', 'webhook'])],
            // every action key must be listed, otherwise validate() drops it
            'actions.*.relationship_id' => ['nullable', 'exists:data_object_relationships,id'],
            'actions.*.delay_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'actions.*.template_id' => ['nullable', 'exists:email_templates,id'],
            'actions.*.subject' => ['nullable', 'string', 'max:255'],
            'actions.*.html' => ['nullable', 'string', 'max:500000'],
            'actions.*.from_email' => ['nullable', 'email'], 'actions.*.from_name' => ['nullable', 'string', 'max:120'],
            'actions.*.body' => ['nullable', 'string', 'max:1600'],
            'actions.*.tag' => ['nullable', 'string', 'max:120'],
            'actions.*.title' => ['nullable', 'string', 'max:255'], 'actions.*.description' => ['nullable', 'string'],
            'actions.*.due_in_minutes' => ['nullable', 'integer', 'min:0', 'max:5256000'],
            'actions.*.assign_to' => ['nullable', 'exists:users,id'],
            'actions.*.field' => ['nullable', 'string', 'max:100'], 'actions.*.value' => ['nullable'],
            'actions.*.endpoint_id' => ['nullable', 'exists:webhook_endpoints,id'],
        ]);

        // Required parameters per action type
        foreach ($d['actions'] ?? [] as $i => $a) {
            $need = match ($a['type']) {
                'send_email' => (! empty($a['template_id']) || (! empty($a['subject']) && ! empty($a['html']))) ? null : 'an email template, or a subject and message',
                'send_sms' => empty($a['body']) ? 'a message' : null,
                'add_tag', 'remove_tag' => empty($a['tag']) ? 'a tag' : null,
                'create_task' => empty($a['title']) ? 'a task title' : null,
                'update_field' => empty($a['field']) ? 'a field' : null,
                'webhook' => empty($a['endpoint_id']) ? 'a webhook endpoint' : null,
            };
            abort_if($need, 422, 'Action '.($i + 1).' ('.str_replace('_', ' ', $a['type']).") needs $need.");
        }

        if (! $partial) {
            $d['conditions'] ??= [];
        }

        // Dry-run the conditions so a bad filter is rejected now, not in a queue worker at 3am.
        $objectId = $d['data_object_id'] ?? ($partial ? $request->route('automation')?->data_object_id : null);
        if (isset($d['conditions']) && $objectId) {
            try {
                (new RecordQuery(DataObject::with('fields')->findOrFail($objectId)))->build($d['conditions'])->toSql();
            } catch (InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }
        }
        abort_if(($d['actions'] ?? false) && collect($d['actions'])->contains(fn ($a) => in_array($a['type'], ['update_field'])) && ! $objectId, 422, 'Field updates need a data object.');

        return $d;
    }
}
