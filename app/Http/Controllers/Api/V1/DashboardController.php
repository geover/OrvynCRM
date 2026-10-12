<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\EmailMessage;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        // Heavy aggregates are cached for 5 minutes; per-user task numbers stay live.
        $global = Cache::remember('dashboard:global:v3', 300, fn () => [
            'contacts' => Contact::count(),
            'new_contacts_30d' => Contact::where('created_at', '>=', now()->subDays(30))->count(),
            'emails_sent_30d' => EmailMessage::where('status', 'sent')->where('sent_at', '>=', now()->subDays(30))->count(),
            'open_rate_30d' => round(100 * EmailMessage::where('status', 'sent')->where('sent_at', '>=', now()->subDays(30))->where('open_count', '>', 0)->count()
                / max(1, EmailMessage::where('status', 'sent')->where('sent_at', '>=', now()->subDays(30))->count()), 1),
            // the same measures for the 30 days before that, so the tiles can show a trend
            'previous' => [
                'new_contacts_30d' => Contact::whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])->count(),
                'emails_sent_30d' => EmailMessage::where('status', 'sent')->whereBetween('sent_at', [now()->subDays(60), now()->subDays(30)])->count(),
                'open_rate_30d' => round(100 * EmailMessage::where('status', 'sent')->whereBetween('sent_at', [now()->subDays(60), now()->subDays(30)])->where('open_count', '>', 0)->count()
                    / max(1, EmailMessage::where('status', 'sent')->whereBetween('sent_at', [now()->subDays(60), now()->subDays(30)])->count()), 1),
            ],
            'objects' => DataObject::withCount('records')->get(['id', 'name'])->map->only(['id', 'name', 'records_count'])->values()->all(),  // plain arrays: the cache cannot unserialize Collections
        ]);

        return response()->json(['data' => $global + [
            'my_open_tasks' => Task::open()->where('assigned_to', $user->id)->count(),
            'my_overdue_tasks' => Task::overdue()->where('assigned_to', $user->id)->count(),
            'recent_activity' => $user->hasPermission('contacts.view') ? Activity::with('causer:id,name', 'contact:id,first_name,last_name')->whereNotNull('contact_id')->latest('id')->limit(10)->get()
                ->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'at' => $a->created_at?->toIso8601String(), 'by' => $a->causer?->name,
                    'contact' => $a->contact ? ['id' => $a->contact->id, 'name' => $a->contact->full_name] : null,
                    'summary' => $a->data['subject'] ?? $a->data['title'] ?? $a->data['body'] ?? null])->all() : [],
            'upcoming_tasks' => Task::open()->where('assigned_to', $user->id)->with('contact:id,first_name,last_name')->orderBy('due_at')->limit(8)->get(),
        ]]);
    }
}
