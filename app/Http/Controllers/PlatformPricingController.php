<?php

namespace App\Http\Controllers;

use App\Services\Tenancy\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The price of an additional user, data object and automation. Companies see it when they ask for more. */
class PlatformPricingController extends Controller
{
    public function show(Pricing $pricing): Response
    {
        return Inertia::render('Platform/Pricing', [
            'pricing' => $pricing->current(),
            'currencies' => Pricing::CURRENCIES,
            'periods' => Pricing::PERIODS,
        ]);
    }

    public function update(Request $request, Pricing $pricing): RedirectResponse
    {
        $d = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'data_object_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'automation_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['required', Rule::in(Pricing::CURRENCIES)],
            'period' => ['required', Rule::in(Pricing::PERIODS)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $pricing->save($d);

        return back()->with('success', collect([$d['price'], $d['data_object_price'] ?? null, $d['automation_price'] ?? null])->every(fn ($v) => $v === null) ? 'Saved. No price is shown to companies.' : 'Pricing saved.');
    }
}
