<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Tenant;
use App\Services\Auth\TwoFactor;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform administrators are managed here and only here. They are not company users: no company lists them, and a
 * company administrator cannot add, edit or remove them.
 */
class PlatformAdministratorController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $request->attributes->get('platform_identity');

        return Inertia::render('Platform/Administrators', [
            'administrators' => AccountUser::where('is_platform_admin', true)->orderBy('name')->get()->map(fn (AccountUser $a) => [
                'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'status' => $a->status, 'is_me' => $a->is($me), 'two_factor' => $a->two_factor_confirmed_at !== null,
            ])->values(),
            'companies' => Tenant::orderBy('name')->get()->map(fn (Tenant $t) => ['id' => $t->getTenantKey(), 'name' => $t->name])->values(),
        ]);
    }

    /** Add a platform administrator: a new person, or an existing account that is promoted. */
    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['nullable', Password::min(10)->mixedCase()->numbers()],
        ]);

        $existing = AccountUser::where('email', mb_strtolower(trim($d['email'])))->first();
        if ($existing?->is_platform_admin) {
            throw ValidationException::withMessages(['email' => 'That person is already a platform administrator.']);
        }

        $identity = $existing ?? $provisioner->identity($d['name'], $d['email'], $d['password'] ?? null);
        $provisioner->makePlatformAdmin($identity);
        if (! $existing && empty($d['password'])) {
            PasswordBroker::sendResetLink(['email' => $identity->email]);
        }

        return back()->with('success', "{$identity->name} is now a platform administrator.");
    }

    public function update(Request $request, AccountUser $administrator): RedirectResponse
    {
        abort_unless($administrator->is_platform_admin, 404);
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('account_users', 'email')->ignore($administrator->id)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);
        if (($d['status'] ?? 'active') !== 'active') {
            abort_if($administrator->is($request->attributes->get('platform_identity')), 422, 'You cannot suspend yourself.');
        }
        $administrator->update($d);

        return back()->with('success', 'Saved.');
    }

    /** A platform administrator who lost their phone and recovery codes: another one turns it off for them. */
    public function resetTwoFactor(Request $request, AccountUser $administrator, TwoFactor $twoFactor): RedirectResponse
    {
        abort_unless($administrator->is_platform_admin, 404);
        abort_if($administrator->is($request->attributes->get('platform_identity')), 422, 'Turn your own off in your sign-in security page.');
        $twoFactor->reset($administrator);

        return back()->with('success', 'Two-step verification was reset. They are told by e-mail.');
    }

    public function sendReset(AccountUser $administrator): RedirectResponse
    {
        abort_unless($administrator->is_platform_admin, 404);
        PasswordBroker::sendResetLink(['email' => $administrator->email]);

        return back()->with('success', 'Password reset link sent.');
    }

    /** Demote: platform access ends; optionally the person stays on as an ordinary administrator of one company. */
    public function destroy(Request $request, AccountUser $administrator, TenantProvisioner $provisioner): RedirectResponse
    {
        abort_unless($administrator->is_platform_admin, 404);
        abort_if($administrator->is($request->attributes->get('platform_identity')), 422, 'You cannot demote yourself.');
        $d = $request->validate(['company' => ['nullable', 'string']]);
        $company = ! empty($d['company']) ? Tenant::findOrFail($d['company']) : null;

        $provisioner->demotePlatformAdmin($administrator, $company);

        return back()->with('success', $company ? "{$administrator->name} is now an administrator of {$company->name}." : "{$administrator->name} is no longer a platform administrator.");
    }
}
