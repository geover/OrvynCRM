<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An administrator's own tab. `placement` says where it shows: "contact" (a tab on every contact's page) or "sidebar" (a page in the main left-hand menu).
 * `audience` says who sees it: null for everybody, or {roles, templates, users} for chosen people (administrators always see every tab, so they can check them).
 */
class CustomTab extends Model
{
    public const PLACEMENTS = ['contact', 'sidebar'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'audience' => 'array'];
    }

    public function isFor(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if (data_get($user->permissionTemplate?->permissions, "tabs.custom:{$this->id}") === false) {         // switched off in the person's permission template
            return false;
        }
        $a = $this->audience;
        if (! $a) {
            return true;
        }

        return in_array($user->role, (array) ($a['roles'] ?? []), true)
            || ($user->permission_template_id && in_array((int) $user->permission_template_id, array_map('intval', (array) ($a['templates'] ?? [])), true))
            || in_array((int) $user->id, array_map('intval', (array) ($a['users'] ?? [])), true);
    }
}
