<?php

namespace App\Http\Controllers;

use App\Models\Signup;
use App\Services\Tenancy\Signups;
use App\Services\Tenancy\SignupSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Platform > Sign-ups: whether anybody can create a workspace, and the requests waiting for a decision. */
class PlatformSignupController extends Controller
{
    public function index(SignupSettings $settings): Response
    {
        $rows = Signup::latest('id')->limit(100)->get()->map(fn (Signup $s) => [
            'id' => $s->id, 'company' => $s->company_name, 'name' => $s->fullName(), 'email' => $s->email, 'phone' => $s->phone, 'status' => $s->status,
            'expired' => $s->isExpired() && ! in_array($s->status, [Signup::COMPLETED, Signup::REJECTED], true), 'note' => $s->decision_note,
            'created_at' => $s->created_at?->toIso8601String(), 'tenant_id' => $s->tenant_id,
        ]);

        return Inertia::render('Platform/Signups', [
            'mode' => $settings->mode(), 'signups' => $rows,
            'counts' => ['waiting' => $rows->where('status', Signup::PENDING_APPROVAL)->count(), 'completed' => Signup::where('status', Signup::COMPLETED)->count(), 'open' => Signup::whereIn('status', [Signup::PENDING_EMAIL, Signup::VERIFIED, Signup::APPROVED])->count()],
        ]);
    }

    public function mode(Request $request, SignupSettings $settings): RedirectResponse
    {
        $settings->save($request->validate(['mode' => ['required', 'in:'.implode(',', SignupSettings::MODES)]])['mode']);

        return back()->with('success', match ($settings->mode()) {
            SignupSettings::OPEN => 'Sign-ups are open: anyone can create a workspace after confirming their e-mail.',
            SignupSettings::APPROVAL => 'Sign-ups need your approval: each request waits here after the e-mail is confirmed.',
            default => 'Sign-ups are closed.',
        });
    }

    public function approve(Signup $signup, Signups $signups): RedirectResponse
    {
        $signups->approve($signup);

        return back()->with('success', "{$signup->company_name} was approved. They have been e-mailed a link to choose a password.");
    }

    public function reject(Request $request, Signup $signup, Signups $signups): RedirectResponse
    {
        $signups->reject($signup, $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null);

        return back()->with('success', "{$signup->company_name} was declined.");
    }
}
