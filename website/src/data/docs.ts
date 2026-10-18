/**
 * The documentation: every page, section and block, in one place. Change the words here; the pages only lay them out.
 * Everything described is something the product does today (it follows the in-app manual and the API). Keep it true.
 * Inline: **bold**, *italic* and `code` work inside any text.
 */

export type Block =
    | { p: string }
    | { h: string }
    | { list: string[] }
    | { steps: string[] }
    | { table: { head: string[]; rows: string[][] } }
    | { note: string }
    | { tip: string }
    | { warn: string }
    | { code: { label?: string; text: string } };

export type DocSection = { id: string; title: string; lead?: string; blocks: Block[] };
export type DocPage = { slug: string; title: string; icon: string; summary: string; sections: DocSection[] };

export const docs: DocPage[] = [
    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'getting-started',
        title: 'Getting started',
        icon: 'check',
        summary: 'What you need, how to open your workspace and a first-day checklist.',
        sections: [
            {
                id: 'what-you-need',
                title: 'What you need',
                lead: 'Very little. A browser and an e-mail address are enough to begin.',
                blocks: [
                    {
                        table: {
                            head: ['You need', 'Why', 'Required?'],
                            rows: [
                                ['A work e-mail address', 'It is your sign-in. Your workspace is created from it.', 'Yes'],
                                ['A modern browser', 'Chrome, Edge, Firefox or Safari, on a computer, tablet or phone.', 'Yes'],
                                ['Your contacts as a CSV file', 'To bring your existing people in on day one. You can also start from nothing and add them as you go.', 'Optional'],
                                ['A Twilio account', 'Only if you want text messages to go out from your own number. Without one, texts use the shared account when it is on.', 'Optional'],
                                ['A Keap account', 'Only if you want to keep your contacts in step with Keap.', 'Optional'],
                                ['A web page of your own', 'Only if you want a sign-up or enquiry form that feeds straight into your contacts.', 'Optional'],
                            ],
                        },
                    },
                    { note: 'You do not need a mail server or a developer. E-mail is sent through the platform’s mail service, and nothing has to be installed.' },
                ],
            },
            {
                id: 'open-your-workspace',
                title: 'Open your workspace',
                lead: 'Three ways in, depending on how your company joined.',
                blocks: [
                    {
                        steps: [
                            '**Create your own workspace.** Choose **Create an account**, give your company name and your details, and confirm your e-mail address from the message we send. Choose a password and your workspace is created at that moment, with you as its administrator. Some platforms approve each request first; the page then tells you to wait for an e-mail.',
                            '**Be invited.** An administrator adds you by e-mail. You receive a link to choose your password, or a password they set for you.',
                            '**Sign in.** Enter your work e-mail, then your password. If your e-mail belongs to more than one company you pick one, and you can switch later from your account menu.',
                        ],
                    },
                    { tip: 'A new workspace starts with a free trial on the first plan. You can enter your billing details at any time, even on day one. See Plans and billing.' },
                ],
            },
            {
                id: 'the-ideas',
                title: 'The ideas you need',
                lead: 'Six words cover almost everything in the product.',
                blocks: [
                    {
                        table: {
                            head: ['Word', 'What it means'],
                            rows: [
                                ['Contact', 'A person or business you deal with: name, e-mails, phones, address, tags, notes, tasks and a full history.'],
                                ['Data object', 'A kind of record you define yourself, such as Deals, Properties or Courses. Its records can be linked to contacts.'],
                                ['Field', 'One piece of information on a data object: text, number, date, drop-down and more.'],
                                ['Automation', 'A rule that watches a data object and, when an item matches your conditions, sends an e-mail or text, adds a tag, creates a task, changes a field or calls another system.'],
                                ['Workflow board', 'A column view of a data object, grouped by a status field, where you drag cards from one column to the next.'],
                                ['Administrator', 'A person who can change how the workspace is set up. Everyone else works with the data.'],
                            ],
                        },
                    },
                ],
            },
            {
                id: 'first-day',
                title: 'A first-day checklist',
                lead: 'In the order that gets you to something useful fastest.',
                blocks: [
                    {
                        steps: [
                            '**Make it yours.** Under Admin settings, *App details* and *Look & feel*, set the company name, your time zone, your logo and your colours.',
                            '**Describe your business.** In *Data Objects*, create the record types you really have (for example Deals or Properties) and add their fields. See Your data.',
                            '**Bring your people.** Import contacts from a CSV, or sync them from Keap. See Contacts.',
                            '**Invite your team.** Under *User management*, add people and give each a permission template.',
                            '**Set up sending.** Check that e-mail and text are ready (the automation editor tells you if they are not), and write a first e-mail template. See Messaging.',
                            '**Let it run.** Create one automation, for example a follow-up task when a deal is added, and watch its first run in the history. See Automations.',
                        ],
                    },
                    { note: 'Your menu depends on your role. If a place described here is missing for you, it is for administrators only, or your administrator has not given you access to it.' },
                ],
            },
            {
                id: 'find-your-way',
                title: 'Finding your way around',
                blocks: [
                    {
                        table: {
                            head: ['Where', 'What is there'],
                            rows: [
                                ['Sidebar', 'Dashboard, Contacts, Data Objects, Workflow Boards, My Tasks, Schedules and, for administrators, Admin Settings.'],
                                ['Search box', 'Searches contacts and records as you type. Press **Ctrl+K** (**Cmd+K** on a Mac) for the command palette: jump to any page, find a contact, or create a contact or task.'],
                                ['Bell', 'Your notifications: tasks, import and export results, notices about your company.'],
                                ['Account menu', 'User settings, switch company, light or dark theme, Admin settings and Sign out.'],
                            ],
                        },
                    },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'contacts',
        title: 'Contacts',
        icon: 'users',
        summary: 'Find, organise, import, merge and talk to the people you deal with.',
        sections: [
            {
                id: 'the-contact-list',
                title: 'The contact list',
                lead: 'Everyone you do business with, with search, filters, saved views and bulk actions.',
                blocks: [
                    {
                        list: [
                            'Search by name, e-mail, company or phone as you type.',
                            'Filter by **tag** or **owner**, and sort by newest, last name, company or recently updated.',
                            '**Columns** chooses what the table shows; **Views** switches between your saved views.',
                            '**Advanced search** builds precise searches from conditions and saves them as queries (see Saved queries below).',
                        ],
                    },
                    { h: 'Act on many at once' },
                    { p: 'Tick the boxes at the start of the rows and a bar offers the bulk actions:' },
                    { list: ['Add or remove a **tag** for everyone selected.', 'Assign an **owner**.', '**Opt out**: they no longer receive messages from automations or blasts.', '**Delete**: moved out of the lists; their notes, tasks and links are kept for audit.'] },
                ],
            },
            {
                id: 'the-contact-record',
                title: 'The contact record',
                lead: 'Everything about one person, in tabs, with the common actions one click away.',
                blocks: [
                    { p: 'The buttons at the top are **Email**, **Text** and **Call**, then **Edit** and a menu with **Merge duplicate**, **Export contact** and **Delete contact**.' },
                    {
                        table: {
                            head: ['Tab', 'What it holds'],
                            rows: [
                                ['Overview', 'Quick info (job, company, website, birthday, other e-mails and phones), addresses, status, owner, type, tags, dates and your own custom fields, plus the contact’s notes.'],
                                ['Notes & tasks', 'Add notes and tasks (with a due date and a priority), search them, complete them, see what is overdue.'],
                                ['Data', 'One section per data object linked to this contact. **Add item** creates or links a record.'],
                                ['Communication', 'E-mail and text history with status, sends, opens and clicks. Open a message to preview it, or resend a failed one.'],
                                ['Relationships', 'Direct links to other contacts, such as Spouse or Manager.'],
                                ['Attachments', 'Upload files or attach a link. Files can be downloaded again by anyone with access.'],
                                ['Activity', 'A timeline of everything that happened to this contact.'],
                                ['Custom tabs', 'Extra tabs your administrator added, for example an embedded page of your own.'],
                            ],
                        },
                    },
                    { h: 'Opt-outs and suppression' },
                    { p: 'A contact is either **Subscribed** or **Opted out**; click the status to change it. Someone who unsubscribes, bounces or reports spam is also placed on a suppression list and skipped from then on, so you never write to people who asked you not to.' },
                ],
            },
            {
                id: 'merge-duplicates',
                title: 'Merging duplicates',
                blocks: [
                    {
                        steps: [
                            'Open the contact you want to **keep** and choose **Merge duplicate** from the menu.',
                            'Search for the duplicate by name or e-mail.',
                            'Choose what happens to the duplicate’s notes, tasks and data-object links.',
                            'Press **Merge contacts**. Empty fields on the one you keep are filled from the duplicate, tags and e-mails move across, and the duplicate is removed.',
                        ],
                    },
                    { warn: 'A merge cannot be undone. Look at the two records side by side first.' },
                ],
            },
            {
                id: 'import-export',
                title: 'Importing and exporting',
                lead: 'Bring data in from a spreadsheet, or take a copy out. Large files run in the background.',
                blocks: [
                    { h: 'Import from CSV' },
                    {
                        steps: [
                            'Open *Data import and export* in Admin settings and choose the **Import type**: Contacts, or a data object.',
                            'Choose the CSV file. The first rows are read and a preview of the columns appears.',
                            'Match each CSV column to a field, or choose *skip*.',
                            'For data objects pick an **Identifying field** so existing items are updated instead of duplicated, or *none, always create new*.',
                            'Press **Start import**. You are told from the bell when it finishes.',
                        ],
                    },
                    { tip: 'Try a handful of rows first. A wrong column mapping is quicker to fix before thousands of records are created.' },
                    { h: 'Export to CSV' },
                    { p: 'Choose what to export (all contacts, notes and tasks, or one data object) and which records, then **Export to CSV**. The finished file appears under *Recent jobs* with a download link. Your data is yours: you can take all of it out whenever you like.' },
                    { h: 'Keap sync' },
                    { p: 'If you use Keap, paste your key under *Keap sync*, test it, and ask your platform administrator to switch the sync on. Contacts are then kept in step on a schedule, and **Sync now** runs it on demand.' },
                ],
            },
            {
                id: 'saved-queries',
                title: 'Saved queries and widgets',
                lead: 'A saved search you can reuse, put on the dashboard, and mail.',
                blocks: [
                    {
                        steps: [
                            'Open *Query builder* and press **New query**.',
                            'Choose what you are searching: **Contacts**, or a **data object**.',
                            'Add criteria. For data objects you can also add criteria on the linked contact and choose which relationship they must have.',
                            'Pick the result columns, **Preview**, and save.',
                        ],
                    },
                    { p: 'A query can power a **dashboard widget**, for example *deals closing this month*. Widgets that list contacts also offer **Email all** and **Text all**: people who opted out or are suppressed are skipped automatically, and the messages are queued in the background.' },
                ],
            },
            {
                id: 'tasks-and-schedules',
                title: 'Tasks and schedules',
                blocks: [
                    { list: ['**My tasks** shows what is open, overdue and completed. Add a task in a line, give it a date, tick it when done. Tasks created on a contact (or by an automation) appear here too.', '**Schedules** is a calendar of your tasks and of the dates your administrator chose to show from data objects, such as viewing dates or deadlines.', 'Overdue tasks are e-mailed to their owner as a digest, so nothing slips quietly.'] },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'data-objects',
        title: 'Your own data',
        icon: 'boxes',
        summary: 'Create the record types your business really has, with their own fields, boards and tabs.',
        sections: [
            {
                id: 'what-a-data-object-is',
                title: 'What a data object is',
                lead: 'Your own record type. Deals, Properties, Courses, Subscriptions, Tours: whatever your business actually tracks.',
                blocks: [
                    { p: 'Each data object has its own list, filters, fields and history, and its records can be linked to contacts with a relationship name such as *Owner* or *Buyer*. On a contact, the **Data** tab shows the same records from the person’s point of view.' },
                    { p: 'Nothing needs a developer: you design the object in the **object builder**, and it is ready to use as soon as you save.' },
                ],
            },
            {
                id: 'object-builder',
                title: 'Build an object',
                blocks: [
                    {
                        steps: [
                            'Open **Data Objects** and press **New object**, or open one and choose **Fields & settings**.',
                            'Give it a **name** (for example Deals) and a description.',
                            'Press **Add field** for each piece of information and set its label, type and options.',
                            'Under **Contact relationships**, add the ways a contact can be linked, such as Owner or Buyer, and whether only one contact is allowed.',
                            'Save.',
                        ],
                    },
                    { h: 'Field types' },
                    { p: 'Text, long text, number, date, date and time, drop-down, multi-select, checkbox and more. For drop-downs and multi-selects you enter the choices, one per line.' },
                    { h: 'Field options' },
                    {
                        table: {
                            head: ['Option', 'Effect'],
                            rows: [
                                ['Field key', 'The internal name used by automations, merge fields and the API. Lowercase letters, numbers and underscores. **It cannot change after the field is created.**'],
                                ['Section', 'Groups fields under a heading in the form.'],
                                ['Required', 'A record cannot be saved without it.'],
                                ['Show as a column', 'Adds the field as a column in the object’s list.'],
                                ['Searchable and filterable', 'Turn on for fields you filter, sort or use in automations. Existing records are indexed in the background.'],
                                ['Value differs per linked contact', 'Stores the value once for each linked contact instead of once for the record.'],
                                ['Placeholder, default, help text', 'Guidance and a starting value for the person filling in the form.'],
                            ],
                        },
                    },
                    { warn: 'Removing a field hides its values but keeps them. **Deleting an object deletes all of its records and cannot be undone.**' },
                ],
            },
            {
                id: 'working-with-records',
                title: 'Working with records',
                blocks: [
                    {
                        list: [
                            '**Search** records or open **Filters** to narrow by field values.',
                            '**New item** opens a form with the tabs **Details**, **Contacts** (who it is linked to, and as what), **Attachments** and **History**.',
                            'Every change is recorded in the item’s history.',
                            '**Import / export** moves records in and out by CSV.',
                        ],
                    },
                ],
            },
            {
                id: 'boards-and-planning',
                title: 'Boards, schedules and other planning tools',
                blocks: [
                    {
                        list: [
                            '**Workflow boards** show a data object as columns, one per value of a status field. Drag a card to another column and the change is saved at once.',
                            '**Scheduler** chooses which data-object dates appear on the Schedules calendar.',
                            '**Custom tabs** add your own tab to every contact page, with content of your own or an embedded https page (shown in a sandboxed frame, scripts disabled).',
                            '**Contact relationships** define the kinds of link between two contacts: direct ones such as Spouse, and indirect ones that arise through shared data.',
                            '**Price lists** can be built, locked and published as a read-only share link. **Tours** schedule visits and e-mail everyone involved.',
                        ],
                    },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'automations',
        title: 'Automations',
        icon: 'zap',
        summary: 'How automations work, every action they can take, and how to build one step by step.',
        sections: [
            {
                id: 'how-they-work',
                title: 'How automations work',
                lead: 'An automation watches one data object. When an item matches your conditions, it runs its actions.',
                blocks: [
                    {
                        table: {
                            head: ['Part', 'What it is'],
                            rows: [
                                ['Data object', 'The kind of record the automation watches, for example Deals.'],
                                ['When to check', 'How often the conditions are tested (see below).'],
                                ['If this…', 'The conditions an item must meet. Leave it empty to run for every item.'],
                                ['Then do…', 'One or more actions, run in the order you list them.'],
                            ],
                        },
                    },
                    {
                        list: [
                            '**Once per item.** An automation runs for an item the first time it matches. Checking again does not repeat it, so an every-minute check never sends the same e-mail twice.',
                            '**Linked contacts.** E-mail, text, tag and task actions go to each contact linked to the item, or only to those with a relationship you choose (for example just the *Buyer*).',
                            '**Opt-outs are respected.** Contacts who opted out or are suppressed are skipped.',
                            '**Recorded.** Every run is written down with the result of each action, so you can always see what happened and why.',
                        ],
                    },
                ],
            },
            {
                id: 'when-to-check',
                title: 'When to check',
                lead: 'Four choices, set on each automation.',
                blocks: [
                    {
                        table: {
                            head: ['Choice', 'When it is tested', 'Use it for'],
                            rows: [
                                ['As soon as an item is created or edited', 'Right after the change, for that item only. Usually within seconds.', 'Instant reactions: a welcome e-mail, a task for a new lead.'],
                                ['Every minute', 'Once a minute, over all the object’s items.', 'Conditions that change with time, such as a date that has just passed.'],
                                ['Hourly', 'At the start of every hour in your company’s time zone.', 'Regular checks that do not need to be instant.'],
                                ['Daily at midnight', 'Once a day, at midnight in your company’s time zone.', 'Daily digests and clean-ups.'],
                            ],
                        },
                    },
                    { note: 'Your time zone is set under *App details*. Timed checks are started by the platform’s scheduler, and the work itself is done by background workers, usually within moments.' },
                ],
            },
            {
                id: 'conditions',
                title: 'Conditions',
                lead: 'Say which items should trigger it.',
                blocks: [
                    { p: 'Build conditions from rules on the object’s fields. Combine them so that **all** or **any** of them must be true, and group rules inside rules for more complex cases.' },
                    {
                        table: {
                            head: ['Field type', 'What you can test'],
                            rows: [
                                ['Text', 'is, is not, contains, starts with, is empty, is not empty'],
                                ['Number', 'equals, does not equal, greater than, at least, less than, at most, is empty, is not empty'],
                                ['Date and date-time', 'on, after, on or after, before, on or before, is empty, is not empty'],
                                ['Drop-down, multi-select, checkbox', 'is, is not, is any of, is empty, is not empty'],
                            ],
                        },
                    },
                ],
            },
            {
                id: 'actions',
                title: 'The actions',
                lead: 'Seven things an automation can do. Combine as many as you need.',
                blocks: [
                    {
                        table: {
                            head: ['Action', 'What it does', 'You set'],
                            rows: [
                                ['Send email', 'Queues an e-mail to each linked contact.', 'A template, or your own subject and body; sender name and address if you want different ones; a delay.'],
                                ['Send SMS', 'Queues a text to each linked contact who has a phone number.', 'The message; a delay.'],
                                ['Add tag', 'Tags each linked contact. The tag is created if it does not exist.', 'The tag.'],
                                ['Remove tag', 'Removes a tag from each linked contact.', 'The tag.'],
                                ['Create task', 'Creates a task for each linked contact.', 'A title and description, who it is assigned to (the contact’s owner by default), and when it is due (a day later by default).'],
                                ['Update a field', 'Changes a value on the item itself. The value is checked against the field type.', 'The field and the new value.'],
                                ['Call webhook', 'Sends the item to one of your webhook endpoints as a signed POST request.', 'The endpoint.'],
                            ],
                        },
                    },
                    { h: 'Options every contact action has' },
                    {
                        list: [
                            '**Only contacts with this relationship.** Leave empty for everyone linked, or choose a relationship such as *Owner*.',
                            '**Delay.** Wait a number of minutes before the e-mail or text goes out.',
                            '**Order.** Move actions up and down; they run top to bottom. A failed action does not stop the ones after it.',
                        ],
                    },
                    { h: 'Merge fields' },
                    { p: 'In e-mail, text, task titles and descriptions, placeholders are replaced with real values for each contact and item:' },
                    {
                        table: {
                            head: ['Placeholder', 'Becomes'],
                            rows: [
                                ['`{{contact.first_name}}`, `{{contact.last_name}}`, `{{contact.full_name}}`', 'The contact’s name.'],
                                ['`{{contact.email}}`, `{{contact.phone}}`', 'Their e-mail and phone.'],
                                ['`{{contact.company_name}}`, `{{contact.job_title}}`', 'Their company and job title.'],
                                ['`{{record.field_key}}`', 'The value of that field on the item that triggered the automation, for example `{{record.price}}`.'],
                                ['`{{app.name}}`, `{{app.url}}`', 'Your workspace name and address.'],
                            ],
                        },
                    },
                    { tip: 'The editor lists the available fields next to each message box and inserts them for you. An unknown placeholder becomes empty rather than showing odd text.' },
                ],
            },
            {
                id: 'build-one',
                title: 'Build an automation, step by step',
                blocks: [
                    {
                        steps: [
                            'Open **Automation** in Admin settings and press **New automation**.',
                            'Name it and choose the **data object** it watches.',
                            'Choose **When to check**.',
                            'Under **If this…** build the conditions, or leave them empty.',
                            'Press **Add action**, choose the type and fill in its details. Add more actions if you need them.',
                            'Check the warnings at the top: if e-mail or text is not set up, the editor tells you, so nothing fails silently.',
                            '**Save automation.** It is active at once.',
                        ],
                    },
                    { p: 'From the list you can pause and resume an automation, **Evaluate** it now, and open its **run history** to see each run and the result of every action.' },
                ],
            },
            {
                id: 'recipes',
                title: 'Three recipes',
                lead: 'Starting points you can copy. Names of objects and fields are yours to change.',
                blocks: [
                    { h: '1. Welcome a new lead' },
                    { list: ['Object: **Leads**. Check: as soon as an item is created or edited. Conditions: none.', 'Action 1: **Send email** with your welcome template to the linked contact.', 'Action 2: **Create task** “Call {{contact.first_name}}”, due in a day.'] },
                    { h: '2. Chase an unpaid invoice' },
                    { list: ['Object: **Invoices**. Check: daily. Conditions: *status is Unpaid* and *due date is before* today’s date.', 'Action 1: **Send SMS** “Hi {{contact.first_name}}, invoice {{record.number}} is overdue.”', 'Action 2: **Update a field**: set *reminder sent* to Yes, so you can see it was done.'] },
                    { h: '3. Tell another system a deal was won' },
                    { list: ['Object: **Deals**. Check: as soon as an item is created or edited. Conditions: *stage is Won*.', 'Action: **Call webhook** to your accounting or chat tool. It receives the item as JSON with a signature you can verify.'] },
                ],
            },
            {
                id: 'limits-and-troubleshooting',
                title: 'Limits and troubleshooting',
                blocks: [
                    {
                        list: [
                            'Your plan limits how many automations each data object may have. At the limit the page offers **Request more**, and shows the price.',
                            'If an action fails, the run history shows its error, and administrators subscribed to failed-automation e-mails are told (at most once an hour per automation).',
                            'Text messages from automations count towards your monthly text limit. When it is used up, further texts are not sent and show as failed with an explanation.',
                            'Automations pause while your company’s background services are switched off by the platform; due work waits and is picked up when they are switched back on.',
                            'Deleting an automation deletes its run history. Actions it already took are not undone.',
                        ],
                    },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'messaging',
        title: 'E-mail and text messages',
        icon: 'mail',
        summary: 'Send, track and manage e-mail and SMS, with templates, delivery reports and limits.',
        sections: [
            {
                id: 'send-from-a-contact',
                title: 'Sending to a contact',
                blocks: [
                    { h: 'E-mail' },
                    {
                        steps: [
                            'On the contact press **Email**.',
                            'Optionally choose a template group and a template.',
                            'Write the subject and the message. **Merge fields** drops in the contact’s own details.',
                            'Attach files if needed, then **Send email**. Your signature is added at the end.',
                        ],
                    },
                    { p: 'Messages are queued and sent by a background worker, usually within moments. The Communication tab shows the status and later the opens and clicks. A failed message shows its error and can be resent. Use the test option to send a copy to yourself first.' },
                    { h: 'Text messages' },
                    { p: 'Press **Text**, type the message and send. A counter shows how long it is. Texts go through your own Twilio account if you set one up, otherwise through the platform’s when it offers one. If you have more than one texting number, you choose which to send from.' },
                ],
            },
            {
                id: 'templates',
                title: 'E-mail templates',
                blocks: [
                    { list: ['Create templates in groups, with a name, subject and body and merge fields.', '**Preview** shows how it will read; **Duplicate** makes a copy to adapt.', 'Everyone chooses a group and a template in the composer, and automations can use a template too.'] },
                ],
            },
            {
                id: 'tracking',
                title: 'Tracking, bounces and opt-outs',
                blocks: [
                    {
                        list: [
                            'Opens and clicks are counted on each e-mail and summarised on the contact and the dashboard.',
                            'Bounces, spam reports and unsubscribes are recorded and the address is **suppressed**, so you stop writing to it. If an address was suppressed by mistake, you can remove it from the list on the Communication tab.',
                            'Sending is rate limited to protect your sending reputation.',
                            '**Blast progress** shows, for your company and for each user, how many e-mails and texts are waiting, sent or failed in the last days.',
                        ],
                    },
                ],
            },
            {
                id: 'sms-setup',
                title: 'Texting with Twilio',
                lead: 'Optional. Without your own account, the platform’s is used when it offers one.',
                blocks: [
                    {
                        steps: [
                            'Open *Twilio* in Admin settings and enter your **Account SID** and **Auth token** (on the front page of the Twilio console).',
                            'Enter a **Send from** number, or a **Messaging Service SID**.',
                            'Press **Test the account** to check Twilio accepts it. The token is stored encrypted and never shown again.',
                            'Choose your **texting numbers** and the default one.',
                        ],
                    },
                    {
                        list: [
                            'Delivery reports come back to a callback address shown on *App details*, so each text’s status lands on the right message.',
                            '**Remove details** returns you to the platform’s account.',
                            'Your plan sets the number of texting numbers and the texts per month. When the monthly limit is used up, further texts are not sent and show as failed with an explanation; the count starts again on the first of the month.',
                            'Need more? Administrators can **ask for more texts** from Billing and plan, for every month or for one month only.',
                        ],
                    },
                    { h: 'Phone calls' },
                    { p: 'The **caller ID** is the number a contact sees when someone calls from the CRM. When the platform lets companies set them, choose one per user. Numbers must be owned or verified in your Twilio account.' },
                ],
            },
            {
                id: 'delivery-reports',
                title: 'Delivery reports and e-mail providers',
                blocks: [
                    { p: 'E-mail goes out through the platform’s mail service, which can be SendGrid or any SMTP server. With SendGrid, bounces, spam reports, unsubscribes and clicks are reported back and applied to the right company automatically. You do not have to set anything up for this: it is done once, by the platform.' },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'forms-and-webhooks',
        title: 'Forms and webhooks',
        icon: 'form',
        summary: 'Capture leads from your website, and connect to other systems in both directions.',
        sections: [
            {
                id: 'forms',
                title: 'Forms that feed your contacts',
                lead: 'Build a form, put it on your website, and every answer becomes a contact.',
                blocks: [
                    {
                        steps: [
                            'Open the **Form builder** and press **Create form**.',
                            'Name it and add fields: short text, long text, drop-down, checkbox and more. Fields are mapped to your contact and data-object fields.',
                            'Write the thank-you message and **Save form**.',
                            'From the forms list copy the embed code and paste it into your page.',
                        ],
                    },
                    {
                        table: {
                            head: ['Way to use it', 'When'],
                            rows: [
                                ['JavaScript embed (recommended)', 'A single line that resizes itself to fit your page.'],
                                ['HTML iframe', 'Where scripts are not allowed in your page.'],
                                ['Direct link', 'To share by e-mail or on social media, without a website.'],
                            ],
                        },
                    },
                    { p: 'Each submission creates or updates a contact. A hidden trap field catches simple bots, and submissions are rate limited. You can read every submission from the form’s page.' },
                    { tip: 'Our own demo form works exactly this way: it was built in OrvynCRM, and every request lands as a contact in our CRM.' },
                ],
            },
            {
                id: 'outbound-webhooks',
                title: 'Outbound webhooks',
                lead: 'Let an automation call another system over HTTPS.',
                blocks: [
                    {
                        steps: [
                            'Open **Webhooks**, press **Add endpoint**, name it and give its HTTPS address. A signing secret is created for it and shown to you once, so copy it then.',
                            'In an automation add the **Call webhook** action and choose the endpoint.',
                            'When the automation fires, the item is sent to your address as a POST request.',
                        ],
                    },
                    { p: 'Each delivery is recorded with the response your system gave, and a failed one is tried again several times over the following hours. The body is JSON:' },
                    { code: { label: 'Request body', text: '{\n  "event": "automation.triggered",\n  "id": 1842,\n  "data": { "record": { "id": 311, "data": { "stage": "Won", "price": 12000 } } }\n}' } },
                    { p: 'Two headers come with it: `X-Orvyn-Event` (the event name) and `X-Orvyn-Signature`, which is `sha256=` followed by the HMAC-SHA256 of the raw body, made with the endpoint’s secret. Check it before you trust a request:' },
                    {
                        code: {
                            label: 'Verify the signature (Node.js)',
                            text: "import { createHmac, timingSafeEqual } from 'node:crypto';\n\nfunction isFromOrvyn(rawBody, header, secret) {\n  const expected = 'sha256=' + createHmac('sha256', secret).update(rawBody).digest('hex');\n  return header.length === expected.length && timingSafeEqual(Buffer.from(header), Buffer.from(expected));\n}",
                        },
                    },
                    { warn: 'Deleting an endpoint makes every automation that calls it fail. Check which automations use it first.' },
                ],
            },
            {
                id: 'inbound',
                title: 'Reports coming in',
                blocks: [
                    { p: 'Your providers can also call OrvynCRM: SendGrid reports bounces, spam reports, unsubscribes and clicks, and Twilio reports the delivery of each text. Each company has its own private callback address (shown on *App details*, and replaceable if it ever leaks), so a report always lands on the right company’s message.' },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'team-and-admin',
        title: 'Your team and workspace',
        icon: 'shield',
        summary: 'Users, permissions, your own look, and how to get more from the platform.',
        sections: [
            {
                id: 'users-and-permissions',
                title: 'Users and permissions',
                blocks: [
                    {
                        steps: [
                            'Open **User management** and press **Add user**.',
                            'Enter their name and e-mail and choose a role and a **permission template**.',
                            'Set a password, or leave it empty so they receive an invitation to choose their own.',
                        ],
                    },
                    { p: 'If the e-mail already belongs to a person with an account, they are simply added to your company and keep their own password. You can edit a user, send a reset link, or remove them: their contact record, notes and tasks stay.' },
                    { h: 'Permission templates' },
                    { p: 'A template is a named set of permissions you give to several people. It decides:' },
                    { list: ['Contacts: view, create, edit, delete and merge.', 'Parts of a contact: notes and tasks, communication, relationships, attachments.', 'Interface: query widgets, workflow boards, and which sidebar tabs and data-object sections are visible.'] },
                    { note: 'Passwords need at least 10 characters with upper and lower case letters and a number. After a password is changed or reset, the person’s API tokens are revoked.' },
                ],
            },
            {
                id: 'make-it-yours',
                title: 'Make it look like your company',
                blocks: [
                    { list: ['**App details**: company name, time zone, and the callback addresses for your providers.', '**Look & feel**: your logo, favicon and colours, so the workspace looks like yours.', '**Your own settings** (every user): name, time zone, e-mail signature, password, and the light, dark or automatic theme.'] },
                ],
            },
            {
                id: 'talk-to-the-platform',
                title: 'Talk to the platform, ask for more',
                blocks: [
                    { p: 'Administrators can **message the platform team** from inside the app. Each message starts a thread, replies stay in it, and a badge shows a new one.' },
                    { p: 'Your plan has limits for users, data objects, automations per object and texts per month. Wherever you reach one, the page shows a **Request more** button: the request goes to the platform with the price you were quoted, and the answer appears on the same page and in your e-mail.' },
                    { tip: 'Every limit has a meter (“3 of 5”), and you are warned at 80% and again at 100%, so a limit is never a surprise.' },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'plans-and-billing',
        title: 'Plans and billing',
        icon: 'calendar',
        summary: 'Your free trial, what each plan includes, paying by card and what happens at a limit.',
        sections: [
            {
                id: 'trial',
                title: 'The free trial',
                blocks: [
                    { p: 'A workspace created through sign-up starts with a free trial on the first plan. The banner and the Billing page show how many days are left, and you are reminded by e-mail and in the bell 14 days, 7 days and 1 day before it ends. You can enter your billing information at any time, even on day one.' },
                    { warn: 'When the trial ends and no billing information is on file, only the Billing page (and the manual) stays open until an administrator enters it. Nothing is deleted.' },
                ],
            },
            {
                id: 'plans',
                title: 'Plans',
                lead: 'Five plans that grow with you. The platform sets the exact numbers and prices, and the Billing page always shows the current ones.',
                blocks: [
                    {
                        table: {
                            head: ['Plan', 'Active users', 'Texts per month', 'Texting numbers'],
                            rows: [
                                ['Tier 1', '5', '100', '1'],
                                ['Tier 2', '15', '500', '3'],
                                ['Tier 3', '50', '1,000', '5'],
                                ['Tier 4', '100', '3,000', '10'],
                                ['Tier 5', 'Unlimited', '5,000', '20'],
                            ],
                        },
                    },
                    { p: 'Every plan includes contacts, your own data objects, automations, e-mail, forms, webhooks, import and export. These numbers are the defaults and can change; ask us for the current ones.' },
                    { list: ['Moving to a bigger plan raises the limits at once.', 'A smaller plan must still hold what you have: free some users or numbers first.', 'Need a little more of one thing without a bigger plan? Ask for extra users or texts.'] },
                ],
            },
            {
                id: 'paying',
                title: 'Paying by card, or by invoice',
                blocks: [
                    { p: 'When online payment is on, you pay by card on **Stripe’s own secure page**. OrvynCRM never sees or stores your card number. If your trial is still running, the card is taken now but the first charge only comes when the trial ends.' },
                    {
                        list: [
                            '**Manage card & invoices** opens Stripe’s portal: change the card, download invoices, or cancel.',
                            'Changing plan changes the subscription at once; Stripe charges or credits the difference for the rest of the period.',
                            'If a payment fails you are told by e-mail and in the bell, and Stripe tries again.',
                            'Some platforms invoice you instead. Fill in your billing information (legal name, contact, address, tax number, purchase order) and they invoice from it.',
                        ],
                    },
                    { h: 'Cancelling' },
                    { p: 'Cancel from the Subscription card: it ends at the end of the period you paid for, everything works until then, and **Keep my subscription** takes it back. Afterwards you are on the first plan again. Nothing is deleted. To subscribe again, ask the platform from the same card.' },
                ],
            },
            {
                id: 'usage-and-limits',
                title: 'Usage and limits',
                blocks: [
                    { p: 'The Billing page shows **active users**, **texts this month** and **texting numbers** against your plan, with a month-by-month history. What happens at a limit:' },
                    {
                        table: {
                            head: ['Limit', 'At the limit'],
                            rows: [
                                ['Active users', 'You cannot add or reactivate people until you free a seat, ask for more, or choose a bigger plan.'],
                                ['Texts per month', 'Further texts are not sent and show as failed with an explanation. The count starts again on the first of the month.'],
                                ['Texting numbers', 'You cannot add another number.'],
                                ['Data objects, automations per object', 'You can ask for more from the page where you hit it.'],
                            ],
                        },
                    },
                ],
            },
        ],
    },

    // ------------------------------------------------------------------------------------------------------------------------------
    {
        slug: 'api',
        title: 'API for developers',
        icon: 'link',
        summary: 'A JSON REST API over everything in the app: authenticate, read, write, and receive webhooks.',
        sections: [
            {
                id: 'overview',
                title: 'Overview',
                lead: 'Everything you can do in the app, you can do with the API, with the same permissions as the person whose token you use.',
                blocks: [
                    {
                        list: [
                            '**Base address:** `https://YOUR-APP-ADDRESS/api/v1`. Replace `YOUR-APP-ADDRESS` with the address you sign in at, for example `app.yourcompany.com`.',
                            '**Format:** JSON in, JSON out. Send `Accept: application/json` and, with a body, `Content-Type: application/json`.',
                            '**Permissions:** a token can do exactly what its person can. A user without permission to edit contacts gets a 403.',
                            '**Rate limit:** 300 requests a minute per person by default. Heavier use is answered with 429; wait and retry.',
                        ],
                    },
                ],
            },
            {
                id: 'authenticate',
                title: 'Authenticate',
                blocks: [
                    { p: 'Ask for a token with your sign-in details, and the company you belong to if you belong to several:' },
                    {
                        code: {
                            label: 'Request a token',
                            text: 'curl -X POST https://YOUR-APP-ADDRESS/api/v1/auth/token \\\n  -H "Content-Type: application/json" \\\n  -d \'{"email":"you@yourcompany.com","password":"…","company":"your-company-slug","device":"my-script"}\'',
                        },
                    },
                    { code: { label: 'Response', text: '{\n  "token": "1|a8Gk…",\n  "user": { "id": 4, "name": "Ana Reyes", "email": "you@yourcompany.com", "role": "admin" },\n  "company": "your-company-slug"\n}' } },
                    { p: 'Send it on every request as `Authorization: Bearer 1|a8Gk…`. Sign-in tokens last 30 days. You can also create a named token for a script with `POST /me/tokens`, and list or revoke yours with `GET /me/tokens` and `DELETE /me/tokens/{id}`. Resetting or changing a password revokes all of a person’s tokens.' },
                    { warn: 'Treat a token like a password. Keep it out of web pages and code you share, and revoke it when it is no longer needed.' },
                ],
            },
            {
                id: 'first-calls',
                title: 'Your first calls',
                blocks: [
                    {
                        code: {
                            label: 'List contacts, newest first',
                            text: 'curl https://YOUR-APP-ADDRESS/api/v1/contacts?per_page=25 \\\n  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"',
                        },
                    },
                    {
                        code: {
                            label: 'Create a contact',
                            text: 'curl -X POST https://YOUR-APP-ADDRESS/api/v1/contacts \\\n  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \\\n  -d \'{"first_name":"Maria","last_name":"Santos","email":"maria@example.com","phone":"+15551234567","company_name":"Santos Realty"}\'',
                        },
                    },
                    {
                        code: {
                            label: 'Add an item to a data object',
                            text: 'curl -X POST https://YOUR-APP-ADDRESS/api/v1/data-objects/3/records \\\n  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \\\n  -d \'{"data":{"title":"Beach house","price":420000,"stage":"New"}}\'',
                        },
                    },
                    {
                        code: {
                            label: 'Send an e-mail to a contact',
                            text: 'curl -X POST https://YOUR-APP-ADDRESS/api/v1/contacts/17/email \\\n  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \\\n  -d \'{"subject":"Your viewing","html":"<p>Hi {{contact.first_name}}, see you Friday.</p>"}\'',
                        },
                    },
                ],
            },
            {
                id: 'lists-and-errors',
                title: 'Lists, filters and errors',
                blocks: [
                    {
                        list: [
                            '**Pagination:** lists use cursor pagination. Ask for up to 100 per page with `per_page`, and follow the `next` link in the response for more. It stays fast however deep you go.',
                            '**Contacts** filter with `filter[q]` (search), `filter[tag]`, `filter[owner_id]`, `filter[contact_type]`, `filter[company_name]` and `filter[created_after]`, and sort with `sort=last_name` or `sort=-created_at`.',
                            '**Records** of a data object take `q` and a `filter` of conditions on its fields.',
                        ],
                    },
                    {
                        table: {
                            head: ['Status', 'Meaning'],
                            rows: [
                                ['401', 'No token, or it expired or was revoked. Ask for a new one.'],
                                ['402', 'The trial has ended and billing details are missing. An administrator completes them on the Billing page.'],
                                ['403', 'The person behind the token is not allowed to do that.'],
                                ['404', 'It does not exist, or it belongs to another company.'],
                                ['422', 'Something you sent is invalid. The body lists each field and what is wrong with it.'],
                                ['429', 'Too many requests. Wait a moment and retry.'],
                            ],
                        },
                    },
                ],
            },
            {
                id: 'resources',
                title: 'What the API covers',
                lead: 'Paths are under `/api/v1`. Resources marked * have the usual list, create, read, update and delete calls.',
                blocks: [
                    {
                        table: {
                            head: ['Area', 'Paths'],
                            rows: [
                                ['Contacts', '`/contacts`*, `/contacts/{id}/notes`, `/contacts/{id}/relationships`, `/contacts/{id}/attachments`, `/contacts/{id}/timeline`, `/contacts/{id}/merge`, `/contacts/{id}/export`, `/contacts/bulk`, `/contacts/duplicates`'],
                                ['Messages', '`/contacts/{id}/email`, `/contacts/{id}/sms`, `/contacts/{id}/messages`, `/messages/queue`, `/messages/blast`, `/email-templates`*'],
                                ['Your data', '`/data-objects`*, `/data-objects/{id}/records` (list, create, read, update, delete), `…/records/{id}/contacts`, `…/history`, `…/attachments`'],
                                ['Automation', '`/automations`*, `/automations/{id}/runs`, `/automations/{id}/run`, `/webhooks`*, `/webhooks/{id}/deliveries`'],
                                ['Work', '`/tasks`*, `/boards`, `/schedules`*, `/calendar`, `/queries`*, `/queries/{id}/run`, `/widgets`*'],
                                ['Forms and data', '`/forms`*, `/forms/{id}/submissions`, `/transfers` (import and export), `/uploads`'],
                                ['Workspace', '`/settings`, `/permission-templates`*, `/custom-tabs`, `/contact-relationship-types`, `/seats`, `/limits`, `/billing`'],
                                ['You', '`/me`, `/me/tokens`, `/notifications`, `/dashboard`, `/search`'],
                            ],
                        },
                    },
                    { note: 'Need something that is not listed, or an example for a particular call? Write to us and we will help.' },
                ],
            },
            {
                id: 'public-endpoints',
                title: 'Public endpoints and webhooks',
                blocks: [
                    { list: ['**Forms** can be read and submitted without a token at `/api/v1/forms/{uuid}` and `/api/v1/forms/{uuid}/submit`, which is how embedded forms work.', '**Outbound webhooks** are signed with HMAC-SHA256. See Forms and webhooks for the headers and a verification example.', '**Inbound reports** from SendGrid and Twilio use private per-company addresses shown under App details.'] },
                ],
            },
        ],
    },
];

/** The overview page: what is on offer, grouped as people think about it, each pointing at the page that explains it. */
export const offer: { icon: string; title: string; text: string; page: string; points: string[] }[] = [
    { icon: 'users', title: 'Contacts', page: 'contacts', text: 'One record per person with notes, tasks, messages, relationships, files and a full history.',
        points: ['Search, filters, saved views and queries', 'Merge duplicates', 'Import and export CSV', 'Keap contact sync'] },
    { icon: 'boxes', title: 'Your own data', page: 'data-objects', text: 'Create the record types your business has, with the fields they need, and link them to contacts.',
        points: ['Deals, properties, courses, anything', 'Workflow boards and a schedule calendar', 'Custom tabs on every contact', 'A history of every change'] },
    { icon: 'zap', title: 'Automations', page: 'automations', text: 'Pick a condition, choose the actions, and let it run. Every run is recorded.',
        points: ['As an item changes, or every minute, hour or day', 'E-mail, text, tag, task, field update, webhook', 'Merge fields, delays and relationship targeting', 'Run history with the result of each step'] },
    { icon: 'mail', title: 'E-mail and text', page: 'messaging', text: 'Write once and reach people where they read. Templates, tracking and delivery reports included.',
        points: ['Composer and reusable templates', 'Opens, clicks, bounces, unsubscribes', 'Texts on the shared account or your own Twilio', 'Phone caller IDs'] },
    { icon: 'form', title: 'Forms and webhooks', page: 'forms-and-webhooks', text: 'Put a form on your website and let every answer land as a contact. Talk to other systems with signed webhooks.',
        points: ['Embed, iframe or direct link', 'Fields mapped to your data', 'Signed outbound webhooks', 'Delivery reports coming in'] },
    { icon: 'shield', title: 'Team and workspace', page: 'team-and-admin', text: 'Decide who sees and does what, and keep the workspace looking like your company.',
        points: ['Users and permission templates', 'Your logo, colours and time zone', 'Message the platform from inside the app', 'Meters for every limit'] },
    { icon: 'calendar', title: 'Plans and billing', page: 'plans-and-billing', text: 'A free trial, five plans, card payment on Stripe or invoicing, and clear limits.',
        points: ['Trial reminders', 'Pay by card or by invoice', 'Ask for more users, data objects, automations or texts', 'Usage by month'] },
    { icon: 'link', title: 'API', page: 'api', text: 'A JSON API over everything in the app, for your own scripts and integrations.',
        points: ['Token sign-in, same permissions as the person', 'Cursor pagination and filters', 'Webhook signatures', 'Examples to copy'] },
];

export const docBySlug = (slug: string) => docs.find((d) => d.slug === slug);
