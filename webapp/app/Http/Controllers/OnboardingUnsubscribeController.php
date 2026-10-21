<?php

namespace App\Http\Controllers;

use App\Models\CompanyOnboarding;
use App\Support\Tenancy\Company;
use Illuminate\Http\Response;

/**
 * The page behind "Stop these tips" in an onboarding e-mail: no sign-in, the unguessable token in the link says which company. The page asks first (a mail
 * program may open links by itself), a button stops the series, another starts it again.
 */
class OnboardingUnsubscribeController extends Controller
{
    public function show(string $token): Response
    {
        $o = $this->find($token);

        return $this->page($o, $o->state === 'unsubscribed' ? 'done' : 'confirm');
    }

    public function confirm(string $token): Response
    {
        $o = $this->find($token);
        $o->state === 'completed' || $o->update(['state' => 'unsubscribed']);

        return $this->page($o, 'done');
    }

    public function resume(string $token): Response
    {
        $o = $this->find($token);
        $o->state === 'unsubscribed' && $o->update(['state' => 'active']);

        return $this->page($o, 'back');
    }

    private function find(string $token): CompanyOnboarding
    {
        return CompanyOnboarding::where('unsubscribe_token', $token)->with('tenant')->firstOrFail();
    }

    private function page(CompanyOnboarding $o, string $state): Response
    {
        return response()->view('onboarding-stop', ['state' => $state, 'company' => $o->tenant?->name ?? 'your workspace', 'brand' => Company::platformBranding()['name'], 'url' => route('onboarding.stop', $o->unsubscribe_token)])
            ->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }
}
