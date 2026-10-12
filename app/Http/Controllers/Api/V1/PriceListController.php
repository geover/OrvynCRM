<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Price Lists: categories -> items (name, description, unit, price) with an optional formula item
 * (e.g. "area * rate"). Locked lists are read-only and shareable through a public token link.
 */
class PriceListController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => PriceList::orderByDesc('updated_at')->get(['id', 'title', 'description', 'form_type', 'is_locked', 'token', 'created_at', 'updated_at'])
            ->map(fn ($p) => $p->toArray() + ['share_url' => url("/p/{$p->token}")])]);
    }

    public function show(PriceList $priceList): JsonResponse
    {
        return response()->json(['data' => $priceList->toArray() + ['share_url' => url("/p/{$priceList->token}")]]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => PriceList::create($this->validated($request))], 201);
    }

    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        abort_if($priceList->is_locked && ! $request->boolean('unlock'), 423, 'This price list is locked.');
        $priceList->update($this->validated($request));

        return response()->json(['data' => $priceList]);
    }

    public function lock(Request $request, PriceList $priceList): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $priceList->update(['is_locked' => $request->boolean('locked', true)]);

        return response()->json(['data' => $priceList->only(['id', 'is_locked'])]);
    }

    public function duplicate(Request $request, PriceList $priceList): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => PriceList::create(['title' => $priceList->title.' (copy)'] + $priceList->only(['description', 'form_type', 'data']))], 201);
    }

    public function destroy(Request $request, PriceList $priceList): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $priceList->delete();

        return response()->json(null, 204);
    }

    /** Public, read-only page for locked lists, reached through the unguessable token. */
    public function publicView(string $token)
    {
        $list = PriceList::where('token', $token)->where('is_locked', true)->firstOrFail();

        return response()->view('prices.public', ['list' => $list]);
    }

    private function validated(Request $request): array
    {
        abort_unless($request->user()->isAdmin(), 403);

        return $request->validate([
            'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'],
            'form_type' => ['required', Rule::in(['standard', 'formula', 'location'])],
            'data' => ['required', 'array'],
            'data.categories' => ['required', 'array', 'max:50'],
            'data.categories.*.name' => ['required', 'string', 'max:150'],
            'data.categories.*.items' => ['present', 'array', 'max:500'],
            'data.categories.*.items.*.name' => ['required', 'string', 'max:255'],
            'data.categories.*.items.*.description' => ['nullable', 'string', 'max:1000'],
            'data.categories.*.items.*.unit' => ['nullable', 'string', 'max:40'],
            'data.categories.*.items.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'data.categories.*.items.*.formula' => ['nullable', 'string', 'max:200', 'regex:/^[0-9a-zA-Z_+\-*\/(). ]*$/'],   // no code execution: arithmetic characters only
            'data.currency' => ['nullable', 'string', 'size:3'],
            'unlock' => ['sometimes', 'boolean'],
        ]);
    }
}
