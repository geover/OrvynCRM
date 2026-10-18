<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\ContactRelationshipType;
use App\Models\CustomTab;
use App\Models\DataTransfer;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Form;
use App\Models\Integration;
use App\Models\MessageThread;
use App\Models\PriceList;
use App\Models\SavedQuery;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Tour;
use App\Models\WebhookEndpoint;
use App\Models\WorkflowBoard;
use App\Services\Tenancy\Limits;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Seats;
use App\Services\Tenancy\SmsSettings;
use App\Services\Tenancy\Subscriptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Admin settings overview: the same cards as the sub-menu, each with a few current facts (how much is set up, what is on or off). */
class AdminOverviewController extends Controller
{
    public function __invoke(Request $request, Plans $plans, Seats $seats, Limits $limits, SmsSettings $sms, Subscriptions $subscriptions): Response
    {
        abort_unless($request->user()->isAdmin(), 403);
        $company = Tenant::findOrFail(tenant('id'));
        $trial = $plans->state($company);
        $sub = $subscriptions->summary($company);
        $n = fn (string $label, int $count) => [$label, (string) $count];
        $twilio = Integration::for('twilio');
        $keap = Integration::for('keap');
        $last = DataTransfer::latest('id')->first();
        $objects = $limits->dataObjects();

        return Inertia::render('Admin/Index', ['facts' => [
            '/admin/app-details' => [['Time zone', (string) Setting::get('app.timezone', config('app.timezone'))]],
            '/admin/look-and-feel' => [['Logo', Setting::get('app.logo_path') ? 'Your own' : 'The platform\'s']],
            '/billing' => [
                ['Plan', $plans->tier((int) $company->tier)['name']],
                ['Paying', $sub['paying'] ? ($sub['cancel_at_period_end'] ? 'Ending with this period' : ($sub['status'] === 'past_due' ? 'Payment failed' : 'Subscribed')) : match ($trial['state']) {
                    'trial' => $trial['days_left'].' '.($trial['days_left'] === 1 ? 'day' : 'days').' of trial left', 'expired' => 'Trial ended', 'subscribed' => 'Billing details on file', default => 'No trial'
                }],
            ],
            '/admin/users' => [['Active users', $seats->usedFor($company).' of '.(Plans::isUnlimitedSeats((int) $company->max_users) ? 'unlimited' : (int) $company->max_users)]],
            '/admin/platform-messages' => [['New replies', (string) MessageThread::unreadForTenant((string) tenant('id'))->count()]],
            '/objects' => [['Data objects', ($objects['used'] ?? 0).' of '.($objects['max'] ?? '?')]],
            '/admin/contact-relationships' => [$n('Types', ContactRelationshipType::count())],
            '/admin/queries' => [$n('Saved queries', SavedQuery::count())],
            '/admin/data-tools' => [$last ? ['Last job', ucfirst($last->direction).' '.str_replace('_', ' ', $last->resource).': '.$last->status] : ['Last job', 'None yet']],
            '/admin/custom-tabs' => [$n('Tabs', CustomTab::count())],
            '/admin/twilio' => [['Texting', $twilio ? 'Your own Twilio account' : ($sms->account() !== null ? 'The platform\'s account' : 'Not set up')]],
            '/admin/keap' => [['Keap', $keap ? 'Key saved' : 'Not connected']],
            '/automations' => [['Active', Automation::active()->count().' of '.Automation::count()]],
            '/admin/webhooks' => [$n('Endpoints', WebhookEndpoint::count())],
            '/admin/email-templates' => [$n('Templates', EmailTemplate::count())],
            '/admin/forms' => [$n('Forms', Form::count())],
            '/admin/blast-progress' => [['Waiting', (EmailMessage::whereIn('status', ['queued', 'sending'])->count() + SmsMessage::whereIn('status', ['queued', 'sending'])->count()).' messages']],
            '/admin/support-emails' => [$n('Addresses', count((array) Setting::get('support.internal_emails', [])))],
            '/admin/email-domains' => [$n('Excluded domains', count((array) Setting::get('email.excluded_domains', [])))],
            '/admin/workflow-boards' => [$n('Boards', WorkflowBoard::count())],
            '/admin/scheduler' => [$n('Schedules', Schedule::count())],
            '/admin/price-lists' => [$n('Price lists', PriceList::count())],
            '/admin/tours' => [$n('Tours', Tour::count())],
        ]]);
    }
}
