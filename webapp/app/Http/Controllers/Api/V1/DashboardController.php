<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /** An administrator hides the getting-started checklist for the whole company (it also goes by itself once everything is done). */
    public function dismissSetup(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        Setting::put('onboarding.dismissed', true);

        return response()->json(null, 204);
    }

    /**
     * The first things to set up in a new workspace, and which are done: what the dashboard shows administrators until they are all done or the
     * checklist is hidden.
     *
     * @return array{steps: list<array<string, mixed>>, done: int, total: int}|null
     */
    private function setup(): ?array
    {
        if (Setting::get('onboarding.dismissed')) {
            return null;
        }
        $steps = [
            ['key' => 'brand', 'title' => 'Make it yours', 'text' => 'Your logo and colours.', 'href' => '/admin/look-and-feel', 'done' => (bool) (Setting::get('app.logo_path') || Setting::get('app.ui_colour'))],
            ['key' => 'data', 'title' => 'Describe your business', 'text' => 'Create the record types you track, with their fields.', 'href' => '/objects', 'done' => DataObject::exists()],
            ['key' => 'contacts', 'title' => 'Bring your contacts', 'text' => 'Import a spreadsheet, or add the first one.', 'href' => '/admin/data-tools', 'done' => Contact::exists()],
            ['key' => 'team', 'title' => 'Invite your team', 'text' => 'Add the people who will work here.', 'href' => '/admin/users', 'done' => User::where('status', 'active')->where('is_platform_support', false)->count() > 1],
            ['key' => 'template', 'title' => 'Write your first e-mail', 'text' => 'A template everyone can reuse.', 'href' => '/admin/email-templates', 'done' => EmailTemplate::exists()],
            ['key' => 'automation', 'title' => 'Automate a follow-up', 'text' => 'Let a rule send, tag or create tasks for you.', 'href' => '/automations', 'done' => Automation::exists()],
        ];
        $done = count(array_filter($steps, fn (array $s) => $s['done']));

        return $done === count($steps) ? null : ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }

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
            'setup' => $user->isAdmin() ? $this->setup() : null,
            'upcoming_tasks' => Task::open()->where('assigned_to', $user->id)->with('contact:id,first_name,last_name')->orderBy('due_at')->limit(8)->get(),
        ]]);
    }
}
