<?php

namespace App\Services\Onboarding;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\EmailMessage;
use App\Models\Form;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowBoard;
use Illuminate\Support\Facades\DB;

/**
 * The steps that carry a new company towards using the system well, in the order we would like them taken, and how to tell that a company has taken one.
 * "Taken" is read from what the company really has (contacts, data, messages sent...), so it does not depend on the company clicking anything.
 */
class Milestones
{
    /** @return list<array{key: string, title: string, short: string, nudge: string, path: string}> */
    public static function all(): array
    {
        return [
            ['key' => 'account', 'title' => 'Create your account', 'short' => 'Account', 'nudge' => 'Your workspace is ready.', 'path' => '/'],
            ['key' => 'contacts', 'title' => 'Add your first contacts', 'short' => 'Contacts', 'nudge' => 'Add a few people you work with, by hand or from a spreadsheet.', 'path' => '/contacts?new=1'],
            ['key' => 'data', 'title' => 'Shape your own data', 'short' => 'Data', 'nudge' => 'Create a record type of your own (deals, properties, courses) or import your records.', 'path' => '/objects/new'],
            ['key' => 'message', 'title' => 'Send your first e-mail or text', 'short' => 'Message', 'nudge' => 'Write to a contact from the contact page and see it recorded there.', 'path' => '/contacts'],
            ['key' => 'automation', 'title' => 'Build your first automation', 'short' => 'Automate', 'nudge' => 'Let the system send a follow-up or create a task by itself.', 'path' => '/automations/new'],
            ['key' => 'team', 'title' => 'Invite a teammate', 'short' => 'Team', 'nudge' => 'Add a colleague, so the work is shared.', 'path' => '/admin/users'],
            ['key' => 'boards', 'title' => 'Set up a board or a form', 'short' => 'Boards', 'nudge' => 'See your records as columns, or collect leads from your website with a form.', 'path' => '/admin/workflow-boards'],
            ['key' => 'brand', 'title' => 'Make it yours', 'short' => 'Brand', 'nudge' => 'Add your logo and colours so it feels like your company.', 'path' => '/admin/look-and-feel'],
        ];
    }

    /** The steps after the account itself: the ones that can still be taken. */
    public static function steps(): array
    {
        return array_values(array_filter(self::all(), fn ($m) => $m['key'] !== 'account'));
    }

    public static function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }

    /**
     * Which steps the company has taken right now.
     *
     * @return array<string, bool>
     */
    public function reached(Tenant $tenant): array
    {
        return $tenant->run(fn () => [
            'account' => true,
            'contacts' => Contact::count() >= 3,
            'data' => DataObject::count() >= 1 || DB::table('data_transfers')->where('direction', 'import')->where('status', 'completed')->exists(),
            'message' => EmailMessage::where('status', 'sent')->exists() || SmsMessage::where('status', 'sent')->exists(),
            'automation' => Automation::exists(),
            'team' => User::where('is_platform_support', false)->count() >= 2,
            'boards' => WorkflowBoard::exists() || Form::exists(),
            'brand' => (bool) (Setting::get('app.logo_path') || Setting::get('app.sidebar_colour') || ((string) Setting::get('app.ui_colour', '#4f46e5')) !== '#4f46e5'),
        ]);
    }
}
