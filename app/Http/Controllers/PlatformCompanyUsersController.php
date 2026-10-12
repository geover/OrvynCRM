<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CompanyUsers;
use App\Services\Tenancy\Seats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'users' => $tenant->run(fn () => User::listed()->orderBy('name')->get(['id', 'name', 'email', 'role', 'status', 'created_at'])->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'status' => $u->status, 'created_at' => $u->created_at?->toIso8601String(),
            ])->values()),
            'used' => $seats->usedFor($tenant),
        ]);
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
