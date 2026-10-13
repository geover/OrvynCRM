<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use App\Services\Tenancy\CallSettings;
use App\Services\Tenancy\CompanyCallerIds;
use App\Services\Tenancy\CompanyUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    private const ADMIN_COLUMNS = ['id', 'name', 'email', 'role', 'status', 'permission_template_id', 'caller_id', 'contact_id', 'timezone', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        // Light list for assignee pickers is open to all; full detail is admin-only.
        $admin = $request->user()->isAdmin();
        $q = User::listed()->orderBy('name');
        $admin && $q->with('permissionTemplate:id,name');

        return response()->json(['data' => $q->get($admin ? self::ADMIN_COLUMNS : ['id', 'name'])->map(fn ($u) => $admin
            ? $u->toArray() + ['template' => $u->permissionTemplate?->name]
            : $u->only(['id', 'name']))]);
    }

    /**
     * Add a user to this company. Without a password an invitation (set-password link) is e-mailed instead.
     * An email that already has an account (another company) is simply added here and keeps its password.
     */
    public function store(Request $request, CompanyUsers $users, CallSettings $calls, CompanyCallerIds $callerIds): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users'],
            'password' => ['nullable', Password::min(10)->mixedCase()->numbers(), Rule::when($request->has('password_confirmation'), ['confirmed'])],
            'role' => ['required', Rule::in(['admin', 'manager', 'user'])],
            'permission_template_id' => ['nullable', 'exists:permission_templates,id'],
            'caller_id' => ['nullable', 'string', 'max:40'],
            'link_contact' => ['boolean'],
        ]);

        if (isset($data['caller_id'])) {
            $calls->ensureCompaniesMaySet();
            $data['caller_id'] = $callerIds->assertUsable($data['caller_id']);
        }

        $contact = ($data['link_contact'] ?? false) ? Contact::where('email', $data['email'])->first() : null;
        $result = $users->add(['contact_id' => $contact?->id] + collect($data)->except(['link_contact'])->all());

        return response()->json(['data' => $result['user']->only(['id', 'name', 'email', 'role']), 'invited' => $result['invited'], 'existing_account' => $result['existing']], 201);
    }

    public function update(Request $request, User $user, CompanyUsers $users, CallSettings $calls, CompanyCallerIds $callerIds): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($user->is_platform_support, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', Password::min(10)->mixedCase()->numbers()],
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'user'])], 'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'permission_template_id' => ['nullable', 'exists:permission_templates,id'],
            'caller_id' => ['nullable', 'string', 'max:40'],
        ]);

        if (array_key_exists('caller_id', $data) && ($data['caller_id'] ?? null) !== $user->caller_id) {
            $calls->ensureCompaniesMaySet();
            $data['caller_id'] = $callerIds->assertUsable($data['caller_id'] ?? null);
        }

        // Never let the last active admin demote or suspend themselves out of the system.
        if ($user->role === 'admin' && (($data['role'] ?? 'admin') !== 'admin' || ($data['status'] ?? 'active') !== 'active')
            && User::listed()->where('role', 'admin')->where('status', 'active')->where('id', '!=', $user->id)->doesntExist()) {
            return response()->json(['message' => 'At least one active administrator is required.'], 422);
        }

        $users->update($user, $data);
        if (($data['status'] ?? null) === 'suspended') {
            $user->tokens()->delete();
        }

        return response()->json(['data' => $user->only(self::ADMIN_COLUMNS)]);
    }

    /** What the Phone page needs to know: the platform's default caller ID, and whether the company may set its own. */
    public function phone(Request $request, CallSettings $calls): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => ['default_caller_id' => $calls->defaultCallerId(), 'can_set' => $calls->companiesMaySetCallerIds()]]);
    }

    public function sendReset(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($user->is_platform_support, 404);
        PasswordBroker::sendResetLink(['email' => $user->email]);

        return response()->json(['sent' => true]);
    }

    public function destroy(Request $request, User $user, CompanyUsers $users): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && $request->user()->id !== $user->id, 403);
        abort_if($user->is_platform_support, 404);
        if ($user->role === 'admin' && User::listed()->where('role', 'admin')->where('id', '!=', $user->id)->doesntExist()) {
            return response()->json(['message' => 'At least one administrator is required.'], 422);
        }
        $users->remove($user);

        return response()->json(null, 204);
    }
}
