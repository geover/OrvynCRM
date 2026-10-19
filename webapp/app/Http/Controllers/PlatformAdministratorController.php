<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Tenant;
use App\Services\Auth\SignInActivity;
use App\Services\Auth\TwoFactor;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\SquareImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
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
    public function index(Request $request, SignInActivity $signIns): Response
    {
        $me = $request->attributes->get('platform_identity');

        $admins = AccountUser::where('is_platform_admin', true)->orderBy('name')->get();
        $activity = $signIns->for($admins->pluck('id'));

        return Inertia::render('Platform/Administrators', [
            'administrators' => $admins->map(fn (AccountUser $a) => [
                'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'status' => $a->status, 'is_me' => $a->is($me), 'two_factor' => $a->two_factor_confirmed_at !== null,
                'created_at' => $a->created_at?->toIso8601String(), 'phone' => $a->phone, 'job_title' => $a->job_title, 'timezone' => $a->timezone, 'notes' => $a->notes, 'avatar_url' => $a->avatarUrl(),
            ] + $activity[$a->id])->values(),
            'companies' => Tenant::orderBy('name')->get()->map(fn (Tenant $t) => ['id' => $t->getTenantKey(), 'name' => $t->name])->values(),
        ]);
    }

    /** The photo, cut to a small square once (see SquareImage). */
    public function uploadAvatar(Request $request, AccountUser $administrator): RedirectResponse
    {
        abort_unless($administrator->is_platform_admin, 404);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp,gif']]);

        $path = "avatars/accounts/{$administrator->id}.jpg";
        Storage::disk('local')->put($path, SquareImage::jpeg((string) file_get_contents($request->file('file')->getRealPath())));
        $administrator->update(['avatar_path' => $path, 'avatar_updated_at' => now()]);

        return back()->with('success', 'Photo saved.');
    }

    public function removeAvatar(AccountUser $administrator): RedirectResponse
    {
        abort_unless($administrator->is_platform_admin, 404);
        $administrator->avatar_path && Storage::disk('local')->delete($administrator->avatar_path);
        $administrator->update(['avatar_path' => null, 'avatar_updated_at' => null]);

        return back()->with('success', 'Photo removed.');
    }

    /** The photo itself, kept by the browser until it changes. */
    public function showAvatar(AccountUser $administrator): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($administrator->is_platform_admin && $administrator->avatar_path && Storage::disk('local')->exists($administrator->avatar_path), 404);

        return response(Storage::disk('local')->get($administrator->avatar_path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Add a platform administrator: a new person, or an existing account that is promoted. */
    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['nullable', Password::min(10)->mixedCase()->numbers()],
            'job_title' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:40'], 'timezone' => ['nullable', 'timezone:all'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $existing = AccountUser::where('email', mb_strtolower(trim($d['email'])))->first();
        if ($existing?->is_platform_admin) {
            throw ValidationException::withMessages(['email' => 'That person is already a platform administrator.']);
        }

        $identity = $existing ?? $provisioner->identity($d['name'], $d['email'], $d['password'] ?? null);
        $details = array_filter(array_intersect_key($d, array_flip(['job_title', 'phone', 'timezone', 'notes'])), fn ($v) => filled($v));
        $details && $identity->update($details);                         // what was typed on the form; an existing account keeps what it already has when the field is left empty
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
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
