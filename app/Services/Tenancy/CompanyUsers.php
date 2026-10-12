<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
use App\Models\Membership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * User management INSIDE the active company, on top of the central identities.
 *
 * What is per company (the profile in the company database): role, status, permission template, caller id, signature,
 * timezone, linked contact. What is per person (the central identity): name, email, password.
 */
class CompanyUsers
{
    /** Number of companies this person belongs to (1 in single-database mode, where there are no memberships). */
    public function companyCount(AccountUser $identity): int
    {
        return max(1, Membership::where('account_user_id', $identity->id)->count());
    }

    public function identityOf(User $profile): ?AccountUser
    {
        return $profile->central_user_id ? AccountUser::find($profile->central_user_id) : null;
    }

    /**
     * Add a person to the active company, creating their central identity if the email is new.
     * An existing identity keeps its password: an administrator of one company must never reset a person's password
     * for every other company; they get the company added and sign in as usual.
     *
     * @param  array<string, mixed>  $data  name, email, role, [password], [permission_template_id], [caller_id], [contact_id]
     * @return array{user: User, invited: bool, existing: bool}
     */
    public function add(array $data): array
    {
        app(Seats::class)->ensureRoom();                 // the company's user limit (none without a company open)

        $identity = AccountUser::where('email', mb_strtolower(trim($data['email'])))->first();
        if ($identity?->is_platform_admin) {
            throw ValidationException::withMessages(['email' => 'This email address cannot be added to a company.']);
        }
        $existing = $identity !== null;
        $password = $data['password'] ?? null;
        $identity ??= AccountUser::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $password ?? Str::random(40)]);

        if ($tenantId = tenant('id')) {
            Membership::firstOrCreate(['account_user_id' => $identity->id, 'tenant_id' => $tenantId]);
        }

        $profile = User::create([
            'central_user_id' => $identity->id, 'name' => $identity->name, 'email' => $identity->email,
            'role' => $data['role'] ?? 'user', 'permission_template_id' => $data['permission_template_id'] ?? null,
            'caller_id' => $data['caller_id'] ?? null, 'contact_id' => $data['contact_id'] ?? null,
        ]);

        app(Seats::class)->sync();

        $invite = ! $existing && $password === null;
        $invite && PasswordBroker::sendResetLink(['email' => $identity->email]);

        return ['user' => $profile, 'invited' => $invite, 'existing' => $existing];
    }

    /**
     * Update a profile; name, email and password go to the central identity.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when a change would affect the person's other companies
     */
    public function update(User $profile, array $data): User
    {
        $identity = $this->identityOf($profile);
        $shared = array_intersect_key($data, array_flip(['name', 'email', 'password']));
        $local = array_diff_key($data, $shared);

        if ($identity && $shared) {
            if ((isset($shared['password']) || (isset($shared['email']) && mb_strtolower($shared['email']) !== $identity->email)) && $this->companyCount($identity) > 1) {
                throw ValidationException::withMessages([isset($shared['password']) ? 'password' : 'email' => 'This person belongs to other companies, so it cannot be changed here. Send them a password reset link instead.']);
            }
            if (isset($shared['email']) && AccountUser::where('email', mb_strtolower($shared['email']))->whereKeyNot($identity->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'That email address already has an account.']);
            }
            $identity->update($shared);
            if (isset($shared['password'])) {
                $this->revokeTokens($identity);
            }
            $local += array_intersect_key($shared, array_flip(['name', 'email']));    // keep this company's copy in step
        }

        // bringing a suspended person back needs a free seat
        if (($local['status'] ?? null) === 'active' && $profile->status !== 'active') {
            app(Seats::class)->ensureRoom(1, 'status');
        }

        $profile->update($local);
        app(Seats::class)->sync();

        return $profile;
    }

    /** Remove a person from the active company. Their identity survives while they belong to another company. */
    public function remove(User $profile): void
    {
        $identity = $this->identityOf($profile);
        $profile->tokens()->delete();
        $profile->delete();

        if ($identity && ($tenantId = tenant('id'))) {
            Membership::where('account_user_id', $identity->id)->where('tenant_id', $tenantId)->delete();
            if (! $identity->is_platform_admin && ! Membership::where('account_user_id', $identity->id)->exists()) {
                $this->revokeTokens($identity);
                $identity->delete();
            }
        }
        app(Seats::class)->sync();
    }

    public function revokeTokens(AccountUser $identity): void
    {
        PersonalAccessToken::withoutGlobalScopes()->where('account_user_id', $identity->id)->delete();
    }
}
