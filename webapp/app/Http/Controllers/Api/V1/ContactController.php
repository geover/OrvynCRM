<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contacts\MergeContacts;
use App\Actions\Contacts\SaveContact;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('contacts.view'), 403);

        $perPage = min((int) $request->integer('per_page', 25), 100);
        $query = QueryBuilder::for(Contact::query()->with('owner:id,name', 'tags:id,name'))
            ->allowedFilters(
                AllowedFilter::callback('q', fn ($q, $v) => $q->search($v)),
                AllowedFilter::callback('tag', fn ($q, $v) => $q->withTag($v)),
                AllowedFilter::exact('owner_id'), AllowedFilter::exact('contact_type'),
                AllowedFilter::partial('company_name'),
                AllowedFilter::callback('created_after', fn ($q, $v) => $q->where('created_at', '>=', $v)),
            )
            ->allowedSorts(
                'first_name', 'last_name', 'email', 'phone', 'company_name', 'created_at', 'updated_at',
                AllowedSort::callback('full_name', fn ($q, bool $desc) => $q->orderBy('first_name', $desc ? 'desc' : 'asc')->orderBy('last_name', $desc ? 'desc' : 'asc')),
                AllowedSort::callback('owner', fn ($q, bool $desc) => $q->orderBy(User::select('name')->whereColumn('users.id', 'contacts.owner_id')->limit(1), $desc ? 'desc' : 'asc')),
            )
            ->defaultSort('-created_at')
            ->orderByDesc('contacts.id');                                           // tie-breaker: rows created in the same second must not be skipped between pages
        // `?page=N` gives numbered pages with totals (the contact table); without it, cursor pagination: O(1) deep pages for API clients and imports
        $contacts = $request->has('page') ? $query->paginate($perPage)->withQueryString() : $query->cursorPaginate($perPage)->withQueryString();

        return ContactResource::collection($contacts);
    }

    public function show(Request $request, Contact $contact): ContactResource
    {
        abort_unless($request->user()->hasPermission('contacts.view'), 403);

        $stats = EmailMessage::where('contact_id', $contact->id)->where('status', 'sent')
            ->selectRaw('count(*) as sent, coalesce(sum(open_count), 0) as opens, coalesce(sum(click_count), 0) as clicks')->first();

        return (new ContactResource($contact->load('owner:id,name', 'tags:id,name')))
            ->additional(['stats' => ['sent' => (int) $stats->sent, 'opens' => (int) $stats->opens, 'clicks' => (int) $stats->clicks]]);
    }

    public function store(ContactRequest $request, SaveContact $save): JsonResponse
    {
        $contact = $save->handle(new Contact, $request->validated(), $request->user());

        return (new ContactResource($contact))->response()->setStatusCode(201);
    }

    public function update(ContactRequest $request, Contact $contact, SaveContact $save): ContactResource
    {
        return new ContactResource($save->handle($contact, $request->validated(), $request->user()));
    }

    /** "Duplicate?" check used by the add-contact form: same e-mail, same phone digits, or same full name. */
    public function duplicates(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.view'), 403);
        $d = $request->validate(['email' => ['nullable', 'string'], 'phone' => ['nullable', 'string'], 'first_name' => ['nullable', 'string'], 'last_name' => ['nullable', 'string'], 'except' => ['nullable', 'integer']]);

        $q = Contact::query()->when($d['except'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->where(function ($w) use ($d) {
            $any = false;
            if (filled($d['email'] ?? null)) {
                $w->orWhere('email', $d['email'])->orWhere('email_secondary', $d['email']);
                $any = true;
            }
            if (filled($d['phone'] ?? null) && strlen(preg_replace('/\D/', '', $d['phone'])) >= 7) {
                $w->orWhere('phone', $d['phone'])->orWhere('phone_secondary', $d['phone']);
                $any = true;
            }
            if (filled($d['first_name'] ?? null) && filled($d['last_name'] ?? null)) {
                $w->orWhere(fn ($n) => $n->where('first_name', $d['first_name'])->where('last_name', $d['last_name']));
                $any = true;
            }
            $any || $w->whereRaw('1 = 0');
        });

        return response()->json(['data' => $q->limit(10)->get(['id', 'first_name', 'last_name', 'email', 'phone', 'company_name'])->map(fn ($c) => $c->toArray() + ['full_name' => $c->full_name])]);
    }

    /** Single-contact export (JSON download) incl. notes, tasks and linked records. */
    public function export(Request $request, Contact $contact)
    {
        abort_unless($request->user()->hasPermission('contacts.view'), 403);
        $contact->load('tags:id,name', 'notes', 'tasks', 'dataObjectRecords.dataObject:id,name');

        return response()->json(['contact' => $contact])->header('Content-Disposition', 'attachment; filename="contact-'.$contact->id.'.json"');
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        abort_unless($request->user()->hasPermission('contacts.delete'), 403);
        $contact->delete();   // soft delete

        return response()->json(null, 204);
    }

    /** Bulk actions from the contacts list: tag / untag / set owner / email opt-out / delete (max 500 per call). */
    public function bulk(Request $request): JsonResponse
    {
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['add_tag', 'remove_tag', 'set_owner', 'opt_out', 'opt_in', 'delete'])],
            'value' => ['nullable', 'string', 'max:120'],
        ]);
        abort_unless($request->user()->hasPermission($d['action'] === 'delete' ? 'contacts.delete' : 'contacts.edit'), 403);
        if (in_array($d['action'], ['add_tag', 'remove_tag'], true)) {
            abort_if(blank($d['value'] ?? null), 422, 'Enter a tag name.');
        }
        if ($d['action'] === 'set_owner' && filled($d['value'] ?? null)) {
            abort_unless(User::whereKey($d['value'])->exists(), 422, 'That user does not exist.');
        }

        // Set-based statements: a handful of queries however many contacts are selected (it was 2 per contact).
        $ids = Contact::whereIn('id', $d['ids'])->pluck('id');
        DB::transaction(function () use ($ids, $d) {
            $tag = in_array($d['action'], ['add_tag', 'remove_tag'], true) ? Tag::firstOrCreate(['name' => trim($d['value'])]) : null;
            match ($d['action']) {
                'add_tag' => $ids->chunk(2000)->each(fn ($chunk) => DB::table('contact_tag')->insertOrIgnore(
                    $chunk->map(fn ($id) => ['contact_id' => $id, 'tag_id' => $tag->id, 'created_at' => now()])->all())),
                'remove_tag' => $ids->chunk(2000)->each(fn ($chunk) => DB::table('contact_tag')->whereIn('contact_id', $chunk)->where('tag_id', $tag->id)->delete()),
                'set_owner' => Contact::whereIn('id', $ids)->update(['owner_id' => filled($d['value'] ?? null) ? (int) $d['value'] : null]),
                'opt_out' => Contact::whereIn('id', $ids)->update(['email_opt_out' => true]),
                'opt_in' => Contact::whereIn('id', $ids)->update(['email_opt_out' => false]),
                'delete' => Contact::whereIn('id', $ids)->delete(),   // soft delete (one UPDATE)
            };
        });

        return response()->json(['affected' => $ids->count()]);
    }

    public function merge(Request $request, Contact $contact, MergeContacts $merge): ContactResource
    {
        abort_unless($request->user()->hasPermission('contacts.merge'), 403);
        $data = $request->validate([
            'duplicate_id' => ['required', 'integer', 'exists:contacts,id', Rule::notIn([$contact->id])],
            'notes_tasks' => ['sometimes', 'in:transfer,none'], 'data_objects' => ['sometimes', 'in:transfer,none'],
        ]);

        return new ContactResource($merge->handle($contact, Contact::findOrFail($data['duplicate_id']), $data));
    }
}
