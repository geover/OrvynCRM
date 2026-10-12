<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\Tour;
use App\Services\Messaging\MessageComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tour Manager: plan visits to contacts (with an address + date), archive tours, notify the contacts. */
class TourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Tour::withCount('stops')->when(! $request->boolean('archived'), fn ($q) => $q->where('archived', false))->orderByDesc('starts_on')->get()]);
    }

    public function show(Tour $tour): JsonResponse
    {
        $tour->load('stops.contact:id,first_name,last_name,email,addresses', 'stops.user:id,name');

        return response()->json(['data' => $tour->toArray() + ['stops' => $tour->stops->map(fn ($s) => $s->only(['id', 'contact_id', 'user_id', 'address', 'visit_at', 'status']) + [
            'contact' => ['id' => $s->contact->id, 'name' => $s->contact->full_name, 'email' => $s->contact->email, 'addresses' => $s->contact->addresses ?? []],
            'user' => $s->user?->name,
        ])->values()->all()]]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, new Tour(['created_by' => $request->user()->id]), 201);
    }

    public function update(Request $request, Tour $tour): JsonResponse
    {
        return $this->save($request, $tour, 200);
    }

    public function destroy(Request $request, Tour $tour): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $tour->delete();

        return response()->json(null, 204);
    }

    /** E-mail every stop's contact (merge fields incl. {{record.visit_date}}); respects opt-outs. */
    public function notify(Request $request, Tour $tour, MessageComposer $composer): JsonResponse
    {
        $spec = $request->validate(['subject' => ['required', 'string', 'max:255'], 'html' => ['required', 'string', 'max:100000']]);
        $queued = 0;
        foreach ($tour->stops()->with('contact')->get() as $stop) {
            // {{visit_date}} must be substituted BEFORE composing: the composer blanks tokens it does not know.
            $date = e(optional($stop->visit_at)->format('M j, Y g:ia') ?? 'to be confirmed');
            $m = $composer->queueEmail($stop->contact, ['subject' => str_replace('{{visit_date}}', $date, $spec['subject']), 'html' => str_replace('{{visit_date}}', $date, $spec['html'])]);
            if ($m) {
                $m->update(['user_id' => $request->user()->id]);
                SendEmailMessage::dispatch($m->id);
                $queued++;
            }
        }

        return response()->json(['queued' => $queued], 202);
    }

    private function save(Request $request, Tour $tour, int $status): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tours.manage') || $request->user()->isAdmin(), 403);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'archived' => ['boolean'],
            'email_settings' => ['nullable', 'array'],
            'stops' => ['sometimes', 'array', 'max:300'],
            'stops.*.contact_id' => ['required', 'distinct', 'exists:contacts,id'],
            'stops.*.user_id' => ['nullable', 'exists:users,id'],
            'stops.*.address' => ['nullable', 'array'], 'stops.*.visit_at' => ['nullable', 'date'],
            'stops.*.status' => ['nullable', 'in:planned,visited,skipped'],
        ]);

        DB::transaction(function () use ($tour, $d) {
            $tour->fill(collect($d)->except('stops')->all())->save();
            if (isset($d['stops'])) {
                $keep = [];
                foreach ($d['stops'] as $s) {
                    $keep[] = $tour->stops()->updateOrCreate(['contact_id' => $s['contact_id']], [
                        'user_id' => $s['user_id'] ?? null, 'address' => $s['address'] ?? Contact::find($s['contact_id'])->addresses[0] ?? null,
                        'visit_at' => $s['visit_at'] ?? null, 'status' => $s['status'] ?? 'planned',
                    ])->id;
                }
                $tour->stops()->whereNotIn('id', $keep)->delete();
            }
        });

        return response()->json(['data' => $tour->fresh()], $status);
    }
}
