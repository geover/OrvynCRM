<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Support\NoticeMail;
use Illuminate\Support\Facades\DB;

/** A notice for every active administrator of a company: an entry in their bell and an e-mail. */
class CompanyAdminNotices
{
    /**
     * @param  list<string>  $lines  the first line is the one shown in the bell's body (after the greeting, the second when there are two)
     */
    public function tell(Tenant $tenant, string $subject, array $lines, string $button = 'Open billing', string $path = '/billing'): void
    {
        $admins = $tenant->run(function () use ($subject, $lines, $path) {
            $admins = User::where('role', 'admin')->where('status', 'active')->where('is_platform_support', false)->get(['id', 'name', 'email']);
            $admins->each(fn (User $a) => DB::table('notifications_feed')->insert([
                'user_id' => $a->id, 'title' => $subject, 'body' => $lines[1] ?? $lines[0], 'url' => $path, 'created_at' => now(), 'updated_at' => now(),
            ]));

            return $admins;
        });

        foreach ($admins as $admin) {
            NoticeMail::send($admin->email, $subject, ["Hi {$admin->name},", ...$lines], [$button, url($path)]);
        }
    }
}
