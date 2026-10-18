<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Services\Tenancy\CompanyUsers;
use App\Services\Tenancy\Seats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function people(Tenant $tenant): Collection
    {
        $rows = $tenant->run(fn () => User::listed()->orderBy('name')->get(['id', 'name', 'email', 'role', 'status', 'created_at', 'central_user_id'])->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'status' => $u->status, 'created_at' => $u->created_at?->toIso8601String(), 'central' => $u->central_user_id,
        ])->values());
        $secured = AccountUser::whereIn('id', $rows->pluck('central')->filter())->whereNotNull('two_factor_confirmed_at')->pluck('id')->all();

        return $rows->map(fn (array $u) => ['two_factor' => in_array($u['central'], $secured, true)] + collect($u)->except('central')->all())->values();
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

    public function store(Request $request, Tenant $tenant, CompanyUsers $users, Seats $seats): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(['admin', 'manager', 'user'])],
            'password' => ['nullable', Password::min(10)->mixedCase()->numbers(), Rule::when($request->has('password_confirmation'), ['confirmed'])],
        ]);

        $result = $tenant->run(function () use ($d, $users, $seats) {
            if (User::where('email', mb_strtolower(trim($d['email'])))->exists()) {
                abort(422, 'That person is already in this company.');
            }
            $seats->ensureRoom();                            // the limit applies to the platform too: raise it first when needed

            return $users->add($d);
        });

        $note = $result['invited'] ? 'An invitation e-mail was sent.' : ($result['existing'] ? 'They already had an account, so their password was not changed.' : '');

        return back()->with('success', trim("{$d['name']} was added to {$tenant->name}. {$note}"));
    }
}
