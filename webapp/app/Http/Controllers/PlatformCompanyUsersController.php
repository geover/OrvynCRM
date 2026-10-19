<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\PermissionTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SignInActivity;
use App\Services\Auth\TwoFactor;
use App\Services\Tenancy\CompanyUsers;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\UserProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Platform administrators adding people to a specific company (within its user limit), without opening the company. */
class PlatformCompanyUsersController extends Controller
{
    public function index(Tenant $tenant, Seats $seats): Response
    {
        return Inertia::render('Platform/CompanyUsers', [
            'company' => ['id' => $tenant->getTenantKey(), 'name' => $tenant->name, 'slug' => $tenant->slug, 'status' => $tenant->status, 'max_users' => (int) $tenant->max_users],
            'users' => $this->people($tenant),
            'used' => $seats->usedFor($tenant),
            'templates' => $tenant->run(fn () => PermissionTemplate::orderBy('name')->get(['id', 'name', 'is_admin'])->values()),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function people(Tenant $tenant): Collection
    {
        $profiles = app(UserProfiles::class);
        $base = "/platform/companies/{$tenant->getTenantKey()}/users";
        $rows = $tenant->run(fn () => User::listed()->with('permissionTemplate:id,name')->orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'status' => $u->status, 'created_at' => $u->created_at?->toIso8601String(), 'central' => $u->central_user_id,
            'permission_template_id' => $u->permission_template_id, 'template' => $u->permissionTemplate?->name, 'phone' => $u->phone, 'job_title' => $u->job_title, 'timezone' => $u->timezone, 'notes' => $u->notes, 'avatar_url' => $profiles->url($u, $base),
        ])->values());
        $secured = AccountUser::whereIn('id', $rows->pluck('central')->filter())->whereNotNull('two_factor_confirmed_at')->pluck('id')->all();
        $signIns = app(SignInActivity::class);
        $activity = $signIns->for($rows->pluck('central'), (string) $tenant->getTenantKey());

        return $rows->map(fn (array $u) => ['two_factor' => in_array($u['central'], $secured, true)] + collect($u)->except('central')->all() + ($activity[$u['central']] ?? $signIns->blank()))->values();
    }

    /** What the company's own Edit user form changes, from the platform (without opening the company). */
    public function update(Request $request, Tenant $tenant, int $user, CompanyUsers $users, UserProfiles $profiles): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'email' => ['sometimes', 'email'],
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'user'])], 'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'permission_template_id' => ['nullable', 'integer'],
        ] + $profiles->rules());

        $tenant->run(function () use ($d, $user, $users) {
            $person = User::listed()->findOrFail($user);
            if (! empty($d['permission_template_id']) && ! PermissionTemplate::whereKey($d['permission_template_id'])->exists()) {
                throw ValidationException::withMessages(['permission_template_id' => 'That template does not exist in this company.']);
            }
            if (isset($d['email']) && User::where('email', mb_strtolower($d['email']))->whereKeyNot($person->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'Someone in this company already has that email address.']);
            }
            // the last active administrator can neither be demoted nor suspended
            if ($person->role === 'admin' && (($d['role'] ?? 'admin') !== 'admin' || ($d['status'] ?? 'active') !== 'active')
                && User::listed()->where('role', 'admin')->where('status', 'active')->whereKeyNot($person->id)->doesntExist()) {
                throw ValidationException::withMessages(['role' => 'At least one active administrator is required.']);
            }
            $users->update($person, $d);
            if (($d['status'] ?? null) === 'suspended') {
                $person->tokens()->delete();
            }
        });

        return back()->with('success', 'Saved.');
    }

    public function uploadAvatar(Request $request, Tenant $tenant, int $user, UserProfiles $profiles): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp,gif']]);
        $tenant->run(fn () => $profiles->save(User::listed()->findOrFail($user), $request->file('file')));

        return back()->with('success', 'Photo saved.');
    }

    public function removeAvatar(Tenant $tenant, int $user, UserProfiles $profiles): RedirectResponse
    {
        $tenant->run(fn () => $profiles->remove(User::listed()->findOrFail($user)));

        return back()->with('success', 'Photo removed.');
    }

    public function avatar(Tenant $tenant, int $user, UserProfiles $profiles): \Symfony\Component\HttpFoundation\Response
    {
        return $tenant->run(fn () => $profiles->response(User::listed()->findOrFail($user)));
    }

    /** Somebody in this company lost their phone and recovery codes. */
    public function resetTwoFactor(Tenant $tenant, int $user, TwoFactor $twoFactor): RedirectResponse
    {
        $centralId = $tenant->run(fn () => User::listed()->whereKey($user)->value('central_user_id'));
        $identity = $centralId ? AccountUser::find($centralId) : null;
        abort_unless($identity, 404);
        $twoFactor->reset($identity);

        return back()->with('success', 'Two-step verification was reset. They are told by e-mail.');
    }

    public function store(Request $request, Tenant $tenant, CompanyUsers $users, Seats $seats, UserProfiles $profiles): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(['admin', 'manager', 'user'])],
            'permission_template_id' => ['nullable', 'integer'], 'link_contact' => ['boolean'],
            'password' => ['nullable', Password::min(10)->mixedCase()->numbers(), Rule::when($request->has('password_confirmation'), ['confirmed'])],
        ] + $profiles->rules());

        $result = $tenant->run(function () use ($d, $users, $seats) {
            if (User::where('email', mb_strtolower(trim($d['email'])))->exists()) {
                abort(422, 'That person is already in this company.');
            }
            $seats->ensureRoom();                            // the limit applies to the platform too: raise it first when needed
            if (! empty($d['permission_template_id']) && ! PermissionTemplate::whereKey($d['permission_template_id'])->exists()) {
                throw ValidationException::withMessages(['permission_template_id' => 'That template does not exist in this company.']);
            }
            $contact = ($d['link_contact'] ?? false) ? Contact::where('email', mb_strtolower(trim($d['email'])))->first() : null;

            return $users->add(['contact_id' => $contact?->id] + collect($d)->except(['link_contact'])->all());
        });

        $note = $result['invited'] ? 'An invitation e-mail was sent.' : ($result['existing'] ? 'They already had an account, so their password was not changed.' : '');

        return back()->with('success', trim("{$d['name']} was added to {$tenant->name}. {$note}"));
    }
}
