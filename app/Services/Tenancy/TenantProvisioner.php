<?php

namespace App\Services\Tenancy;

use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRelationship;
use App\Models\Membership;
use App\Models\PermissionTemplate;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates companies and puts people in them.
 *
 * A person exists once, centrally (AccountUser: email + password). Joining a company adds a Membership (central) and a
 * profile row (App\Models\User) inside that company's database, where role, permission template and caller id live.
 */
class TenantProvisioner
{
    /** Slugs that would collide with routes or read as infrastructure. */
    private const RESERVED = ['www', 'app', 'api', 'admin', 'platform', 'login', 'logout', 'static', 'assets', 'mail', 'support'];

    /**
     * New company: creates its database, migrates it and seeds the defaults (the TenantCreated pipeline in
     * TenancyServiceProvider), then optionally makes $owner its administrator.
     *
     * @param  array{name: string, email: string, password?: ?string}|null  $owner
     */
    public function createCompany(string $name, ?string $slug = null, ?array $owner = null, bool $demo = false, ?int $maxUsers = null, ?int $maxDataObjects = null, ?int $maxAutomationsPerObject = null): Tenant
    {
        $slug = Str::slug($slug ?: $name);
        if ($slug === '' || in_array($slug, self::RESERVED, true) || Tenant::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'That company address is not available.']);
        }

        $tenant = Tenant::create(['name' => $name, 'slug' => $slug, 'status' => 'active', 'max_users' => $maxUsers ?? (int) config('tenancy.default_max_users', 5),
            'max_data_objects' => $maxDataObjects ?? (int) config('tenancy.default_max_data_objects', 10),
            'max_automations_per_object' => $maxAutomationsPerObject ?? (int) config('tenancy.default_max_automations_per_object', 5)]);

        if ($owner) {
            $identity = $this->identity($owner['name'], $owner['email'], $owner['password'] ?? null);
            $profile = $this->addMember($tenant, $identity, 'admin');
            $demo && $tenant->run(fn () => $this->demoData($profile));
        }

        return $tenant;
    }

    /** The central identity for an email, created if it does not exist (never changes an existing person's password). */
    public function identity(string $name, string $email, ?string $password = null, bool $platformAdmin = false): AccountUser
    {
        $existing = AccountUser::where('email', mb_strtolower(trim($email)))->first();
        if ($existing) {
            return $existing;
        }

        return AccountUser::create([
            'name' => $name, 'email' => $email, 'password' => $password ?? Str::random(40), 'is_platform_admin' => $platformAdmin,
        ]);
    }

    /**
     * Put a person in a company: the central membership plus their profile in the company database.
     *
     * @param  array<string, mixed>  $attributes  extra profile columns (caller_id, ...) and `template` (a permission template NAME)
     */
    public function addMember(Tenant $tenant, AccountUser $identity, string $role = 'user', array $attributes = []): User
    {
        Membership::firstOrCreate(['account_user_id' => $identity->id, 'tenant_id' => $tenant->getTenantKey()]);

        $templateName = $attributes['template'] ?? null;      // a template NAME: ids differ between company databases
        unset($attributes['template']);

        return $tenant->run(function () use ($identity, $role, $attributes, $templateName) {
            $template = $templateName ? PermissionTemplate::where('name', $templateName)->first()
                : ($role === 'admin' ? PermissionTemplate::where('is_admin', true)->first() : null);

            $profile = User::updateOrCreate(['central_user_id' => $identity->id], $attributes + [
                'name' => $identity->name, 'email' => $identity->email, 'role' => $role, 'status' => 'active',
                'permission_template_id' => $template?->id,
            ]);
            app(Seats::class)->sync();

            return $profile;
        });
    }

    /**
     * Support access for a platform administrator: a profile inside the company (so audit trails, ownership and
     * permissions work) but NO central membership, so the company never shows up in their normal chooser. The profile
     * is flagged `is_platform_support`: it is hidden from the company's user lists and cannot be edited or removed by
     * the company; the platform manages it.
     */
    public function addSupportProfile(Tenant $tenant, AccountUser $identity): User
    {
        return $tenant->run(function () use ($identity) {
            $template = PermissionTemplate::where('is_admin', true)->first();

            return User::updateOrCreate(['central_user_id' => $identity->id], [
                'name' => $identity->name.' (platform support)', 'email' => $identity->email, 'role' => 'admin', 'status' => 'active',
                'permission_template_id' => $template?->id, 'is_platform_support' => true,
            ]);
        });
    }

    /**
     * Make a person a platform administrator. Platform administrators are not company users: their profiles in
     * companies become hidden support profiles and their memberships end (they reach companies with "Open").
     * Refused when it would leave a company without an administrator.
     *
     * @throws ValidationException
     */
    public function makePlatformAdmin(AccountUser $identity): void
    {
        $orphaned = [];
        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use ($identity, $tenant, &$orphaned) {
                $profile = User::where('central_user_id', $identity->id)->where('is_platform_support', false)->first();
                if ($profile?->role === 'admin' && User::listed()->where('role', 'admin')->where('status', 'active')->whereKeyNot($profile->id)->doesntExist()) {
                    $orphaned[] = $tenant->name;
                }
            });
        }
        if ($orphaned) {
            throw ValidationException::withMessages(['email' => 'This person is the only administrator of '.implode(', ', $orphaned).'. Add another administrator there first.']);
        }

        foreach (Tenant::all() as $tenant) {
            $tenant->run(fn () => User::where('central_user_id', $identity->id)->update(['is_platform_support' => true]));
        }
        Membership::where('account_user_id', $identity->id)->delete();
        $identity->update(['is_platform_admin' => true]);
    }

    /**
     * Take platform administration away. Their support profiles disappear from every company; optionally they stay on
     * as an ordinary administrator of one company.
     */
    public function demotePlatformAdmin(AccountUser $identity, ?Tenant $keepIn = null): void
    {
        $identity->update(['is_platform_admin' => false]);

        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use ($identity, $tenant, $keepIn) {
                $profile = User::where('central_user_id', $identity->id)->first();
                if ($profile && ! ($keepIn && $keepIn->is($tenant))) {
                    $profile->tokens()->delete();
                    $profile->delete();
                }
            });
        }

        if ($keepIn) {
            $this->addMember($keepIn, $identity, 'admin');
            $keepIn->run(fn () => User::where('central_user_id', $identity->id)->update(['is_platform_support' => false, 'name' => $identity->name]));
        }
    }

    /** Take a person out of a company (their identity and other memberships are untouched). */
    public function removeMember(Tenant $tenant, AccountUser $identity): void
    {
        Membership::where('account_user_id', $identity->id)->where('tenant_id', $tenant->getTenantKey())->delete();
        $tenant->run(function () use ($identity) {
            User::where('central_user_id', $identity->id)->delete();
            app(Seats::class)->sync();
        });
    }

    /** The old seed data, for demos and the browser tests: three contacts, a Deals object with items, and tasks. */
    public function demoData(User $owner): void
    {
        if (Contact::exists()) {
            return;
        }
        foreach ([['Ada', 'Lovelace', 'ada@example.com', 'Analytical Co'], ['Grace', 'Hopper', 'grace@example.com', 'Navy Labs'], ['Alan', 'Turing', 'alan@example.com', 'Bletchley']] as [$f, $l, $e, $c]) {
            Contact::create(['first_name' => $f, 'last_name' => $l, 'email' => $e, 'company_name' => $c, 'owner_id' => $owner->id, 'phone' => '+1555010'.random_int(1000, 9999)]);
        }

        $rel = DataObjectRelationship::firstOrCreate(['label' => 'Owner']);
        $deals = DataObject::create(['name' => 'Deals']);
        $deals->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'is_required' => true, 'show_in_list' => true, 'position' => 0],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['choices' => ['New', 'Negotiation', 'Won', 'Lost']], 'is_indexed' => true, 'show_in_list' => true, 'position' => 1],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'is_indexed' => true, 'show_in_list' => true, 'position' => 2],
            ['key' => 'close_date', 'label' => 'Close date', 'type' => 'date', 'is_indexed' => true, 'position' => 3],
        ]);
        $deals->relationships()->attach($rel->id);

        $service = app(RecordService::class);
        foreach (Contact::all() as $i => $contact) {
            $service->create($deals->fresh('fields'), ['title' => "Deal for {$contact->first_name}", 'stage' => 'New', 'amount' => 1000 * ($i + 1)], $owner->id,
                [['contact_id' => $contact->id, 'relationship_id' => $rel->id]]);
            Task::create(['contact_id' => $contact->id, 'assigned_to' => $owner->id, 'title' => "Call {$contact->first_name}", 'due_at' => now()->addDays($i)]);
        }
    }

    public static function hashed(string $plain): string
    {
        return Hash::make($plain);
    }
}
