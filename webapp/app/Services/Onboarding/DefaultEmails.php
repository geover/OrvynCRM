<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingEmail;

/**
 * The series the platform starts with: one welcome, one e-mail for each step a company has not yet taken, and one when everything is done. The platform edits all of it
 * (Platform > Onboarding > E-mails); this only fills in what is missing, and never overwrites a change.
 *
 * Merge fields: {{owner.first_name}} {{owner.name}} {{company.name}} {{app.name}} {{app.url}} {{progress.done}} {{progress.total}} {{progress.percent}}
 *               {{next.title}} {{next.nudge}} {{days.since_signup}} {{trial.days_left}}
 */
class DefaultEmails
{
    /** @return list<array<string, mixed>> */
    public static function series(): array
    {
        return [
            ['key' => 'welcome', 'icon' => '🎉', 'milestone' => null, 'title' => 'Welcome', 'subject' => 'Welcome to {{app.name}}, {{owner.first_name}} 🎉', 'preheader' => 'Your workspace is ready. Here is the quickest way to get value from it.',
                'headline' => 'Your workspace is ready',
                'body' => '<p>Hi {{owner.first_name}},</p><p><strong>{{company.name}}</strong> is set up and waiting for you. {{app.name}} adapts to the way your business works, so the first hour is the best investment you can make.</p><p>We have kept it simple: <strong>seven small steps</strong>, each one unlocks something useful. You do not have to do them all today. We will check in now and then with the next one.</p><ul><li>Add the people you work with</li><li>Shape your own record types</li><li>Write to someone from the contact page</li><li>Let an automation do the follow-up</li></ul>',
                'button_label' => 'Open your workspace', 'button_path' => '/', 'tip' => 'Every contact has Notes, Tasks and Communication on one page, so nothing about a person is scattered.'],
            ['key' => 'contacts', 'icon' => '👥', 'milestone' => 'contacts', 'title' => 'First contacts', 'subject' => '{{owner.first_name}}, let us put some people in {{company.name}}', 'preheader' => 'A CRM comes alive with the first few contacts.',
                'headline' => 'Start with the people',
                'body' => '<p>Hi {{owner.first_name}},</p><p>A CRM comes alive the moment it knows who you work with. Three ways to get your first contacts in, pick the one that is quickest for you:</p><ul><li><strong>Add one by hand</strong>: name, e-mail, phone, done in a minute.</li><li><strong>Import a spreadsheet</strong>: open Company settings, then Data import &amp; export, and upload a CSV. We even generate a template with the right columns.</li><li><strong>Sync from Keap</strong>, if that is where your contacts live now.</li></ul><p>Three contacts is enough to see how everything fits together.</p>',
                'button_label' => 'Add a contact', 'button_path' => '/contacts?new=1', 'tip' => 'The contact list can be shown as a table or as cards, and a saved view remembers your filters and the columns you like.'],
            ['key' => 'data', 'icon' => '🧩', 'milestone' => 'data', 'title' => 'Your own data', 'subject' => 'Make {{app.name}} speak your language', 'preheader' => 'Deals, properties, courses, anything your business keeps track of.',
                'headline' => 'Track what your business really tracks',
                'body' => '<p>Hi {{owner.first_name}},</p><p>Most CRMs make you describe your business in their words. Here you create <strong>data objects</strong>: the record types you actually have, like deals, properties, subscriptions or tours, each with the fields you need.</p><p>They show up as tabs on every contact, keep a history of every change, and can be moved across a board. It takes about five minutes to set up the first one.</p>',
                'button_label' => 'Create a data object', 'button_path' => '/objects/new', 'tip' => 'Each field can be set to take a quarter, a third, half or all of the width of the form, so your forms look the way you want.'],
            ['key' => 'message', 'icon' => '✉️', 'milestone' => 'message', 'title' => 'First message', 'subject' => 'Say hello from {{app.name}}', 'preheader' => 'E-mail and text, written where the contact lives.',
                'headline' => 'Write to someone, from the contact page',
                'body' => '<p>Hi {{owner.first_name}},</p><p>E-mail and text messages are built in, so there is no second tool to keep in sync. Open any contact, press <strong>Email</strong> or <strong>Text</strong>, and the message is sent and recorded on that contact.</p><ul><li>Use a template and merge fields like the contact\'s first name</li><li>Format the e-mail with headings, lists, links and pictures</li><li>See later whether it was opened or clicked</li></ul>',
                'button_label' => 'Open your contacts', 'button_path' => '/contacts', 'tip' => 'Every bulk e-mail carries an unsubscribe link automatically, and people who opt out are skipped for you.'],
            ['key' => 'automation', 'icon' => '⚡', 'milestone' => 'automation', 'title' => 'First automation', 'subject' => 'Let {{app.name}} do the follow-up for you', 'preheader' => 'The work that happens while you sleep.',
                'headline' => 'Your first automation',
                'body' => '<p>Hi {{owner.first_name}},</p><p>The follow-ups that matter are the ones people forget. An <strong>automation</strong> watches your records and, when something matches, sends the e-mail or text, adds a tag, creates a task or calls another system.</p><p>Start small: <em>when a new customer is added, wait one day, then send a welcome e-mail</em>. Every run is written down, so you can always see what happened.</p>',
                'button_label' => 'Build an automation', 'button_path' => '/automations/new', 'tip' => 'You can wait minutes, hours or days between steps, and see the whole sequence as a timeline.'],
            ['key' => 'team', 'icon' => '🤝', 'milestone' => 'team', 'title' => 'Invite the team', 'subject' => 'Bring your team into {{company.name}}', 'preheader' => 'It is better together.',
                'headline' => 'A CRM is better with your team in it',
                'body' => '<p>Hi {{owner.first_name}},</p><p>Shared work is where this pays off: one history per contact, tasks that can be assigned, and nobody asking who spoke to whom last.</p><p>Open <strong>User management</strong>, add a colleague, and choose a permission template that decides what they can see and do. They get an invitation e-mail with a link to choose a password.</p>',
                'button_label' => 'Invite a teammate', 'button_path' => '/admin/users', 'tip' => 'Each person can have a photo, a job title and their own details, so the team feels like a team.'],
            ['key' => 'boards', 'icon' => '📊', 'milestone' => 'boards', 'title' => 'Board or form', 'subject' => 'See your work move, {{owner.first_name}}', 'preheader' => 'Boards for your pipeline, forms for your website.',
                'headline' => 'See your work at a glance',
                'body' => '<p>Hi {{owner.first_name}},</p><p>Two more ways to put your data to work:</p><ul><li><strong>Workflow boards</strong> show any record type as columns by status. Drag a card to move it, open it to see everything and act on it.</li><li><strong>Forms</strong> go on your website, and every answer lands as a contact with the right data.</li></ul><p>Pick whichever fits your day better. Both take a few minutes.</p>',
                'button_label' => 'Set up a board', 'button_path' => '/admin/workflow-boards', 'tip' => 'Click a card on a board and you can e-mail or text the item, or the contacts on it, without leaving the page.'],
            ['key' => 'brand', 'icon' => '🎨', 'milestone' => 'brand', 'title' => 'Make it yours', 'subject' => 'Make {{company.name}} look like {{company.name}}', 'preheader' => 'Your logo and your colours, in two minutes.',
                'headline' => 'Put your name on it',
                'body' => '<p>Hi {{owner.first_name}},</p><p>People use a tool more when it feels like theirs. Under <strong>Look &amp; feel</strong> you can add your logo, choose your theme colour and even colour the sidebar.</p><p>It takes two minutes, and everyone in {{company.name}} sees it right away.</p>',
                'button_label' => 'Add your logo and colours', 'button_path' => '/admin/look-and-feel', 'tip' => 'You can set how tall your logo may be in the sidebar, so a wide or a square logo both look right.'],
            ['key' => 'all_set', 'icon' => '🚀', 'milestone' => null, 'title' => 'All set', 'subject' => 'You are all set, {{owner.first_name}} 🚀', 'preheader' => 'You have used the system the way it was meant to be used.',
                'headline' => 'You did it',
                'body' => '<p>Hi {{owner.first_name}},</p><p>You have taken every step: contacts, your own data, messages, automation, a team, a board or a form, and your own look. That is what {{app.name}} is for.</p><p>From here, the best next move is to let it run. If something is unclear, or you want to talk about how to use it for your business, just reply to this e-mail or write to us from the app. We are glad you are here.</p>',
                'button_label' => 'Go to your workspace', 'button_path' => '/', 'tip' => 'The Manual inside the app explains every page, with examples you can copy.'],
        ];
    }

    /** Adds the e-mails that do not exist yet (by key). @return int how many were added */
    public static function ensure(): int
    {
        $have = OnboardingEmail::pluck('key')->all();
        $added = 0;
        foreach (self::series() as $i => $e) {
            if (in_array($e['key'], $have, true)) {
                continue;
            }
            OnboardingEmail::create($e + ['position' => $i, 'enabled' => true]);
            $added++;
        }

        foreach (self::series() as $e) {                                  // an e-mail made before pictures existed gets its default one (never overwriting a chosen one)
            OnboardingEmail::where('key', $e['key'])->whereNull('icon')->update(['icon' => $e['icon']]);
        }

        return $added;
    }
}
