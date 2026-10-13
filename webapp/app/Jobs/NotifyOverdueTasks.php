<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Once a day per user: "You have N overdue tasks". Idempotent (checks for today's digest first). */
class NotifyOverdueTasks implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(CompanyServices $services): void
    {
        if (! $services->enabled('task_digest')) {
            return;
        }

        // tasks made by the automations of a data object whose digest is switched off are left out; people's own tasks always count
        $paused = tenant('id') ? $services->pausedObjects(tenant('id'), 'task_digest') : [];
        $counts = DB::table('tasks')->whereNull('completed_at')->where('due_at', '<', now())->whereNotNull('assigned_to')
            ->when($paused, fn ($q) => $q->where(fn ($w) => $w->whereNull('data_object_id')->orWhereNotIn('data_object_id', $paused)))
            ->groupBy('assigned_to')->selectRaw('assigned_to, count(*) as total')->pluck('total', 'assigned_to');

        foreach ($counts as $userId => $total) {
            $already = DB::table('notifications_feed')->where('user_id', $userId)->where('url', '/tasks?status=overdue')->where('created_at', '>=', now(Setting::get('app.timezone', 'UTC'))->startOfDay())->exists();
            $already || DB::table('notifications_feed')->insert([
                'user_id' => $userId, 'title' => "You have $total overdue task".($total === 1 ? '' : 's'), 'body' => 'Open My tasks to catch up.',
                'url' => '/tasks?status=overdue', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
