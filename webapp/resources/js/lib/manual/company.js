// The user manual for people working inside a company (everything under the company's own sidebar).
// Drawn by Components/ManualViewer.vue. Keep it in step with the screens: each section names the menu or button as it is labelled.
// Block kinds: p, h, steps, list, tip, note, warn, table { head, rows }, link { label, href }. Inline: **bold**, `code`.

export default {
    groups: [
        {
            title: "Getting started",
            sections: [
                {
                    id: "welcome",
                    title: "Welcome to your workspace",
                    lead: "OrvynCRM keeps everyone you do business with, and everything that happens with them, in one place that belongs to your company alone.",
                    blocks: [
                        { p: "Your company has its own private space: its own people, contacts, settings and look. Nobody from another company can see it. The platform team that runs OrvynCRM looks after the service itself, and you can message them from the admin area." },
                        { h: "The ideas you need" },
                        {
                            table: {
                                head: ["Word", "What it means"],
                                rows: [
                                    ["Contact", "A person or business you deal with: name, e-mails, phones, address, tags, notes, tasks and history."],
                                    ["Data object", "A kind of record you define yourself, such as Deals, Properties or Courses. Records of an object can be linked to contacts."],
                                    ["Field", "One piece of information on a data object (text, number, date, drop-down and so on)."],
                                    ["Automation", "A rule that watches a data object and, when an item matches your conditions, sends an e-mail or text, adds a tag, creates a task and more."],
                                    ["Workflow board", "A column view of a data object, grouped by a status field, where you drag cards from one column to the next."],
                                    ["Administrator", "A person who can change how the workspace is set up. Other users work with the data. See Users and permissions."],
                                ],
                            },
                        },
                        { note: "What you see depends on your role and permission template. If a menu in this manual is missing for you, your administrator has not given you access to it, or it is for administrators only." },
                    ],
                },
                {
                    id: "signing-in",
                    title: "Signing in, and signing up",
                    lead: "One sign-in page serves every company. You type your e-mail first, then your password.",
                    blocks: [
                        { steps: ["Open the address your administrator gave you and enter your **work e-mail**.", "Enter your **password** and sign in. The eye at the end of the box shows or hides what you typed. Tick the remember option to stay signed in on a trusted device.", "If your e-mail belongs to more than one company you land on **Choose a company**. Pick one. You can switch later from your account menu."] },
                        { h: "Forgot your password" },
                        { steps: ["Choose **Forgot password?** on the password step. Your e-mail is already filled in; otherwise type it.", "Press **Email me a reset link**. The page always answers the same way, so nobody can find out which addresses have an account: check your e-mail (and the spam folder), or **send it again**.", "Open the link in the e-mail. It works once and expires after an hour. Choose a new password (the rules are checked as you type, and the eye shows what you typed), type it again and press **Reset password**.", "You are taken to the sign-in page. Sign in with the new password. All your API tokens are revoked, so scripts need a new one."] },
                        { h: "Creating your own workspace" },
                        { p: "When the platform allows it, the sign-in page shows **Create an account**. You give your company name and your details, confirm your e-mail address from the message we send, and then choose a password. Your workspace is created at that moment and you are signed in as its administrator. Some platforms approve each request first; the page then tells you to wait for an e-mail." },
                        { tip: "Too many wrong passwords lock the sign-in for a few minutes. Wait, or use Forgot password." },
                    ],
                },
                {
                    id: "finding-your-way",
                    title: "Finding your way around",
                    lead: "A sidebar for places, a top bar for search and your account, and a few shortcuts for speed.",
                    blocks: [
                        {
                            table: {
                                head: ["Where", "What is there"],
                                rows: [
                                    ["Sidebar", "Dashboard, Contacts, Data Objects, Workflow Boards, My Tasks, Schedules, and (for administrators) Admin Settings. Use the arrow at its top to collapse it to icons."],
                                    ["Search box", "Searches contacts and records as you type. Press **Ctrl+K** (**Cmd+K** on a Mac) to open the command palette from anywhere."],
                                    ["Add contact", "The purple button in the top bar opens the new-contact form wherever you are."],
                                    ["What's new", "The megaphone: updates and news from the platform. A dot shows when there is something unread."],
                                    ["Bell", "Your notifications: tasks, import and export results, notices about your company."],
                                    ["Account menu", "Your initial, top right: User settings, Switch company (if you have several), the light or dark theme, Admin settings and Sign out."],
                                ],
                            },
                        },
                        { h: "The command palette" },
                        { p: "Press **Ctrl+K**, type a few letters and press Enter. It jumps to pages and admin settings, finds contacts and records, and offers actions such as **Create contact** and **Create task**. **Esc** closes it." },
                        { h: "Look and feel for you" },
                        { p: "From the account menu you can switch between light, dark and automatic themes. Tables can be made more compact with the density toggle where it is offered. These choices are yours alone and are remembered in your browser." },
                    ],
                },
            ],
        },
        {
            title: "Everyday work",
            sections: [
                {
                    id: "dashboard",
                    title: "Dashboard",
                    lead: "Your starting page: the numbers that matter, what is due, and what just happened.",
                    blocks: [
                        { list: ["**Quick actions**: Create contact, Create task, Import data, and **Add widget**.", "**Headline tiles**: contacts, new contacts in the last 30 days (compared with the 30 days before), e-mails sent, open rate, your open tasks and how many are overdue.", "**Upcoming tasks**: what is due soon, with the contact it belongs to. Click one to open the contact.", "**Recent activity**: the latest changes across the workspace.", "**Data objects**: a card for each object with its record count."] },
                        { h: "Widgets" },
                        { p: "A widget shows the result of a saved query, for example *deals closing this month*. Press **Add widget**, choose a saved query and a title. On a widget you can **Reload** it, **Download CSV**, **Edit** or **Remove** it. Removing a widget never deletes the query behind it." },
                        { p: "Widgets that list contacts also offer **Email all** and **Text all**. Contacts who opted out, or whose address is suppressed, are skipped automatically and the messages are queued for sending in the background." },
                        { tip: "Cards can be moved and hidden to suit you. Your arrangement is remembered in this browser." },
                        { link: { label: "How to build the queries behind widgets", href: "#query-builder" } },
                    ],
                },
                {
                    id: "contacts",
                    title: "Contacts: finding and organising",
                    lead: "The list of everyone you do business with, with search, filters, saved views and bulk actions.",
                    blocks: [
                        { h: "Find people" },
                        { list: ["Type in the search box: it looks at name, e-mail, company and phone.", "Filter by **Tag** or **owner**, and sort (newest, last name, company, recently updated). **Clear filters** brings everyone back.", "**Columns** lets you choose which columns the table shows. **Views** switches between your saved views.", "**Advanced search** builds precise searches and saves them as queries (see Advanced search and saved queries)."] },
                        { h: "Add a contact" },
                        { steps: ["Press **New contact** (or **Add contact** in the top bar).", "Fill in the name and whatever else you know: job title, company, website, birthday, several e-mails and phones, addresses.", "Save. You land on the new contact."] },
                        { h: "Act on many at once" },
                        { p: "Tick the boxes at the start of the rows (the box in the header selects the page). A bar appears with the available actions:" },
                        { list: ["**Add a tag** or **Remove a tag** for everyone selected.", "**Assign an owner**.", "**Opt out**: they will no longer receive messages from automations or blasts.", "**Delete**: the contacts are moved out of lists. Their notes, tasks and links are kept for audit."] },
                        { warn: "Opt-out and delete apply to every contact you ticked. Check the count in the confirmation before you accept." },
                        { link: { label: "Import contacts from a spreadsheet", href: "#import-export" } },
                    ],
                },
                {
                    id: "contact-record",
                    title: "Working in a contact record",
                    lead: "Everything about one person, in tabs, with the most common actions one click away.",
                    blocks: [
                        { h: "The header" },
                        { p: "The buttons at the top are **Email**, **Text** and **Call**, then **Edit**, and a menu with **Merge duplicate…**, **Export contact** and **Delete contact**. The summary beside the record shows the next task, last activity, linked items and e-mail engagement." },
                        { h: "The tabs" },
                        {
                            table: {
                                head: ["Tab", "What it holds"],
                                rows: [
                                    ["Overview", "Quick info (job, company, website, birthday, other e-mails and phones), addresses, status, owner, type, tags, tracking, dates and custom fields, plus the contact's notes."],
                                    ["Notes & tasks", "Add a **note** or a **task** (with a due date and priority). Search them, mark tasks complete, and see what is overdue."],
                                    ["Data", "One section per data object linked to this contact. **Add item** creates or links a record."],
                                    ["Communication", "E-mail history and SMS history with status, sends, opens and clicks. Open a message to preview it, or **Resend**."],
                                    ["Relationships", "Direct links to other contacts (for example Spouse or Manager). Find a contact to link, or **Disconnect**."],
                                    ["Attachments", "**Upload files** or **Attach link**. Files can be downloaded again by anyone with access."],
                                    ["Activity", "A timeline of everything that happened. **Load more** goes further back."],
                                    ["Custom tabs", "Extra tabs your administrator added, for example an embedded page. They appear under **More** if there is no room."],
                                ],
                            },
                        },
                        { h: "Email subscription" },
                        { p: "In the Overview, the e-mail status shows **Subscribed** or **Opted out**. Click it to change. An opted-out contact receives nothing from automations or blasts." },
                        { h: "Merging duplicates" },
                        { steps: ["Open the contact you want to **keep** (the primary) and choose **Merge duplicate…** from the menu.", "Search for the duplicate by name or e-mail.", "Decide what happens to the duplicate's notes and tasks (move them or leave them behind) and to its data-object links.", "Press **Merge contacts**. Empty fields on the primary are filled from the duplicate, tags and e-mails move across, and the duplicate is removed."] },
                        { warn: "A merge cannot be undone. Look at the two records side by side first." },
                    ],
                },
                {
                    id: "email-and-sms",
                    title: "Sending e-mail and text messages",
                    lead: "Write to a contact from their record. Everything you send is logged on the contact.",
                    blocks: [
                        { h: "E-mail" },
                        { steps: ["On the contact press **Email**.", "Optionally choose a **Template group** and a template with **Use template**.", "Write the **Subject** and the **Message**. **Merge fields** drops in the contact's own details, such as their first name.", "Attach files if you need to, then press **Send email**. Your **signature** (User settings) is added at the end."] },
                        { p: "Messages are queued and sent by a background worker, usually within moments. The Communication tab shows the status, and later the opens and clicks. A failed message shows its error and can be **Resend**." },
                        { tip: "Use the test option to send a copy to yourself before you send the real one." },
                        { h: "Text messages" },
                        { p: "Press **Text**, type your message and **Send SMS**. A counter shows how long it is. Texts go through your company's Twilio account if you have set one up, otherwise through the platform's, when the platform offers one. If neither exists the button explains who to ask." },
                        { h: "Opt-outs and suppression" },
                        { p: "Contacts who unsubscribe, bounce or complain are placed on a suppression list and skipped from then on. If a contact's address was suppressed by mistake, the Communication tab lets you remove it from the list." },
                        { link: { label: "Watch the sending queue (administrators)", href: "#blast-progress" } },
                    ],
                },
                {
                    id: "data-objects",
                    title: "Data objects and their records",
                    lead: "Your own record types. Each object has its own list, filters and fields, and its records can be linked to contacts.",
                    blocks: [
                        { p: "Open **Data Objects** to see every object with its record count. Click one to see its records." },
                        { list: ["**Search** the records, or open **Filters** to narrow by field values, then **Apply filters** or **Clear**.", "**New item** creates a record. In the form, the tabs are **Details**, **Contacts** (who it is linked to and as what), **Attachments** and **History**.", "Records are linked to contacts with a relationship name, for example *Owner* or *Buyer*. Some objects allow only one contact.", "**Import / export** moves records in and out by CSV, and **Fields & settings** (administrators) opens the object builder."] },
                        { p: "On a contact, the **Data** tab shows the same records from the contact's point of view, so you can add or open an item without leaving the person." },
                        { note: "Your plan limits how many data objects the company may have. If you reach it, the page offers **Request more data objects**, which asks the platform and shows its price." },
                    ],
                },
                {
                    id: "boards",
                    title: "Workflow boards",
                    lead: "See where every record stands, and move it forward by dragging.",
                    blocks: [
                        { p: "**Workflow Boards** lists the boards your administrator made. Open one to see columns, one per status, with a card for each record." },
                        { steps: ["Drag a card to another column to change its status. It is saved immediately.", "Click a card to open the record.", "A marker on a card means the contact has outstanding tasks."] },
                        { tip: "Administrators create and edit boards under Admin settings, Workflow boards, and choose which field becomes the columns." },
                    ],
                },
                {
                    id: "tasks-schedules",
                    title: "My tasks and Schedules",
                    lead: "What you have to do, and what is coming up.",
                    blocks: [
                        { h: "My tasks" },
                        { p: "Three lists: **Open**, **Overdue** and **Completed**. Type in the box and press Enter to add a task, then give it a date. Tick a task to complete it, or reopen it with **Mark open**. Tasks created on a contact appear here too." },
                        { h: "Schedules" },
                        { p: "A calendar of your tasks and the dates your administrator chose to show from data objects, such as viewing dates or deadlines. Overdue items are marked in red, today is highlighted, and completed tasks turn green." },
                    ],
                },
                {
                    id: "advanced-search",
                    title: "Advanced search and saved queries",
                    lead: "When the search box is not precise enough.",
                    blocks: [
                        { steps: ["Open **Advanced search** from the contact list or the top bar.", "Choose what you are searching, then **Build a search** with one or more conditions.", "Press **Run query** to see the matches.", "Give it a name and **Save as new query** to reuse it, or open one from **Saved queries**."] },
                        { p: "Saved queries can power dashboard widgets and are the way to target groups of people. Administrators manage them under Admin settings, Query builder." },
                    ],
                },
                {
                    id: "user-settings",
                    title: "Your own settings",
                    lead: "Open the account menu, then **User settings**.",
                    blocks: [
                        {
                            table: {
                                head: ["Section", "What you can do"],
                                rows: [
                                    ["Profile", "Change your name, e-mail and **timezone**. Dates throughout the app follow it."],
                                    ["E-mail signature", "Text added to the end of every e-mail you send from a contact. Save it once."],
                                    ["Change password", "Enter your current password, then a new one: at least 10 characters with upper and lower case letters and a number. Changing it signs out your API tokens."],
                                    ["My blast progress", "How many of your e-mails and texts from the last 7 days are waiting, sent or failed."],
                                    ["2-way e-mail sync", "Linking your mailbox is not available yet. E-mails you send from OrvynCRM are logged on the contact automatically."],
                                ],
                            },
                        },
                    ],
                },
            ],
        },
        {
            title: "Administering the workspace",
            sections: [
                {
                    id: "admin-hub",
                    title: "The Admin settings hub",
                    adminOnly: true,
                    lead: "Everything that configures the workspace lives under **Admin Settings** in the sidebar, grouped by area.",
                    blocks: [
                        { p: "The hub shows a card for each setting, with a search box (**Find a setting**). Inside any admin page a side menu lets you jump to another. A badge on Admin Settings means the platform has replied to one of your messages." },
                        {
                            table: {
                                head: ["Group", "Pages"],
                                rows: [
                                    ["General", "App details, Look & feel, Billing & plan, User management, Phone call, Message the platform"],
                                    ["Data", "Object builder, Contact relationships, Query builder, Data import & export, Custom tabs"],
                                    ["Automation & communication", "Twilio, Keap, Automation, Webhooks, Email templates, Form builder, Blast progress, Internal support emails, Email sync domains"],
                                    ["Planning", "Workflow boards, Scheduler, Price list generator, Tour manager"],
                                ],
                            },
                        },
                    ],
                },
                {
                    id: "app-details",
                    title: "App details",
                    adminOnly: true,
                    lead: "The basics of your workspace and the keys that let other systems talk to it.",
                    blocks: [
                        { list: ["**App name** and **Tagline**: shown in the sidebar and on your sign-in page. Leave the tagline empty to hide it.", "**Timezone**: used for scheduled automations and for how dates are shown.", "**Generated email format**: the placeholder address given to contacts created without an e-mail, built from fields such as Contact ID."] },
                        { h: "API access" },
                        { p: "Scripts and tools such as Zapier can read and write your data with a personal token. The page shows the **Base URL**. Enter a token name and press **Create**, then copy the token at once: it is shown only once. Send it as `Authorization: Bearer …`. **Revoke** a token and anything using it stops immediately." },
                        { h: "Inbound webhooks" },
                        { p: "Delivery reports (bounces, unsubscribes, SMS status) reach your company through two secret addresses: the **SendGrid event webhook** and the **Twilio status callback**. Copy them into your e-mail and SMS providers where those services ask for them. **Generate new URLs** replaces them and the old ones stop working." },
                    ],
                },
                {
                    id: "look-and-feel",
                    title: "Look & feel",
                    adminOnly: true,
                    lead: "Make the workspace look like your company.",
                    blocks: [
                        { list: ["**Theme colour**: the colour of buttons and highlights.", "**Sidebar colour**: tick **Customise the sidebar colour** to give the sidebar its own colour. Light colours switch its text to dark. A preview shows the result.", "**Company logo**: **Choose file** to upload, or **Use platform logo** to go back to the default. A logo made for dark backgrounds reads best on a dark sidebar.", "**Favicon**: the small icon in the browser tab, with the same choice."] },
                        { note: "Logos and favicons accept PNG, JPG and SVG. Very large files are refused with a message that names the limit." },
                    ],
                },
                {
                    id: "users",
                    title: "Users and permissions",
                    adminOnly: true,
                    lead: "Who can sign in, and what each person may do.",
                    blocks: [
                        { h: "Adding people" },
                        { steps: ["Open **User management** and press **Add user**.", "Enter their name and e-mail. Choose a role and a permission template.", "Either set a password, or leave it empty so they receive an invitation to choose their own."] },
                        { p: "If the e-mail already belongs to a person with an account, they are simply added to your company and keep their own password. You can **Edit** a user, send a **reset link**, or **Delete user**: their contact record, notes and tasks stay." },
                        { h: "Permission templates" },
                        { p: "A template is a named set of permissions you give to several people. **Create template**, then choose what the template allows:" },
                        { list: ["Contacts: view, create, edit, delete and merge duplicates.", "Parts of a contact: notes and tasks, communication, relationships, attachments.", "Interface: query widgets, workflow boards, the general interface guide, which sidebar tabs and which data object sections are visible."] },
                        { h: "The user limit" },
                        { p: "Your plan sets how many active users your company may have (5, 15, 50, 100 or unlimited); a meter shows *used of allowed*. When you reach it, **Add user** is replaced by **Request more users**. The platform sees your request with its price and approves or declines it, and you are told. A bigger plan under Billing & plan raises the limit at once." },
                    ],
                },
                {
                    id: "billing-plan",
                    title: "Billing, plan and free trial",
                    adminOnly: true,
                    lead: "What your plan includes, how long your free trial lasts, and where invoices go.",
                    blocks: [
                        { p: "Open **Billing & plan** from the Admin settings hub (or the banner at the top of the page). Everybody in the company can see where the plan and the trial stand; only administrators change them." },
                        { h: "The free trial" },
                        { p: "A workspace created through sign-up starts with a free trial on **Tier 1**. The banner and this page show how many days are left, and you get reminders by e-mail and in the bell 14 days, 7 days and 1 day before it ends. You may enter your billing information at any time, even on day one." },
                        { warn: "When the trial ends and no billing information is on file, only the Billing & plan page (and the manual) stays open, for everybody in the company, until an administrator enters it. Nothing is deleted. People who are not administrators are asked to tell theirs." },
                        { h: "Plans (the platform can change these numbers)" },
                        {
                            table: {
                                head: ["Plan", "Active users", "Texts per month", "Texting numbers"],
                                rows: [
                                    ["Tier 1", "5", "100", "1"],
                                    ["Tier 2", "15", "500", "3"],
                                    ["Tier 3", "50", "1,000", "5"],
                                    ["Tier 4", "100", "3,000", "10"],
                                    ["Tier 5", "Unlimited", "5,000", "20"],
                                ],
                            },
                        },
                        { p: "What each plan includes is set by the platform and can change; the page always shows the current numbers. If the platform gave your company extra texts or texting numbers, the meters include them and say so. The page shows each plan with its price (or *Contact us* when the platform has not set one) and how much of your plan you use: **Active users**, **Texts this month** (it starts again each calendar month) and **Texting numbers**." },
                        { steps: ["Enter your **billing information** first. A plan cannot be chosen without it.", "Press **Upgrade to…** or **Switch to…** on the plan you want and confirm.", "The new limits apply at once, and the platform is told."] },
                        { p: "If you need more texts than your plan allows, press **Ask for more texts** under the *Texts this month* meter (administrators only). Say how many texts a month you need in total and, if you like, why. The platform sees it in its Requests, and you are e-mailed the answer, which also shows under the meter until you dismiss it. Only one request can wait at a time. If it is approved, the new limit applies at once. The platform may approve it for every month (it stays when you change plan) or for the current month only; the meter then says how many of your extra texts are for this month only, and the allowance goes back on the 1st." },
                        { p: "When the platform changes what a plan includes or what it costs, the company's administrators get a notice in the app and an e-mail. A new price applies from your next renewal; nothing is charged or credited at that moment." },
                        { p: "A smaller plan must still hold what you have: if you have more active users than it allows, suspend or remove some first; if you use more texting numbers than it allows, remove numbers under Admin settings, Twilio." },
                        { h: "Usage, warnings and limits" },
                        { p: "The meters show **Active users**, **Texts this month** and **Texting numbers** against your plan, and **Usage by month** lists the last months (most active users, texts sent, numbers). You are told by e-mail and in the bell when you reach **80%** and again at **100%** of one of them." },
                        { list: ["**Users:** at the limit you cannot add or reactivate people until you free a seat, ask for more, or choose a bigger plan.", "**Texts:** at the limit further texts are not sent and show as failed with an explanation. The count starts again on the 1st of each month, in your company's time zone.", "**Texting numbers:** at the limit you cannot add another. If your plan gets smaller, only the first numbers (the default comes first) are kept."] },
                        { h: "Paying by card" },
                        { p: "When the platform has online payment switched on, the plan buttons say **Subscribe to…**. You are taken to Stripe's own secure page to enter your card; this application never sees or stores card numbers. If your free trial is still running, the card is taken now but the first charge only comes when the trial ends. When you are back, the page shows **Subscribed**, the card (brand and last four digits) and the renewal date." },
                        { list: ["**Manage card & invoices** opens Stripe's portal: change the card, download invoices, or cancel.", "**Upgrade to…** or **Switch to…** then changes the subscription at once; Stripe charges or credits the difference for the rest of the period.", "If a payment fails you are told by e-mail and in the bell, and Stripe tries again. Update the card from the portal.", "Some platforms still invoice you themselves: then billing information is all that is asked for."] },
                        { h: "Cancelling, and subscribing again" },
                        { p: "On the **Subscription** card, **Cancel subscription** ends it at the end of the period you already paid for; you may say why. Until then everything works as before, and **Keep my subscription** takes the cancellation back. When it has ended you are on Tier 1 again (5 users, 100 texts a month, 1 texting number). Nothing is deleted, and the platform is told." },
                        { p: "To subscribe again, press **Ask to subscribe again** on the same card (choose a plan if you like and add a message). The platform enables it and you are told by e-mail and in the bell; then the **Subscribe to…** buttons work again. Until then they are greyed out." },
                        { note: "Companies that are invoiced by the platform instead of paying by card have no Stripe subscription to cancel here. To stop or change that plan, move to a smaller plan or write to the platform from Message the platform." },
                        { h: "Billing information" },
                        { p: "Fill in the legal name of the company, the billing contact and the e-mail invoices go to, the address (street, city, postal code, country) and, if you have them, a tax or VAT number and a purchase order reference. No card or bank details are asked for: the platform invoices you from these details. **Complete** appears next to the heading when everything required is filled in." },
                        { tip: "Text messages stop for the month when your texts-per-month limit is used up, and resume on the 1st. Bigger plans raise the limit at once." },
                    ],
                },
                {
                    id: "object-builder",
                    title: "The object builder",
                    adminOnly: true,
                    lead: "Design your own record types: what they are called, what you store, and how they link to contacts.",
                    blocks: [
                        { steps: ["Open **Data Objects** and press **New object**, or open an object and choose **Fields & settings**.", "Give it a **Name** (for example Deals) and a description.", "Press **Add field** for each piece of information and fill in its label, type and options.", "Under **Contact relationships**, add the ways a contact can be linked, such as Owner or Buyer, and whether only one contact is allowed.", "**Save**."] },
                        { h: "Field types" },
                        { p: "Text, long text, number, date, date and time, drop-down, multi-select, checkbox and more. For drop-downs and multi-selects enter the **Choices**, one per line." },
                        { h: "Field options" },
                        {
                            table: {
                                head: ["Option", "Effect"],
                                rows: [
                                    ["Field key", "The internal name used to identify saved values. Lowercase letters, numbers and underscores. **It cannot change after the field is created.**"],
                                    ["Section / group", "Groups fields under a heading in the form."],
                                    ["Required", "A record cannot be saved without it."],
                                    ["Show as a column", "Adds the field as a column in the object's list."],
                                    ["Make searchable & filterable fast", "Turn on for fields you filter, sort or use in automations. Existing records are re-indexed in the background."],
                                    ["Value differs per linked contact", "Stores the value once for each linked contact instead of once for the record."],
                                    ["Placeholder, default value, help text", "Guidance and a starting value for the person filling in the form."],
                                ],
                            },
                        },
                        { warn: "Removing a field hides its values from view but keeps them in the database. **Deleting an object deletes all of its records and cannot be undone.**" },
                    ],
                },
                {
                    id: "relationships",
                    title: "Contact relationships",
                    adminOnly: true,
                    lead: "The kinds of links you can draw between two contacts.",
                    blocks: [
                        { list: ["**Direct relationships**: explicit links such as Spouse or Manager. They appear on the contact's Relationships tab.", "**Indirect relationships**: labels for links that arise through shared data, such as two people linked to the same record."] },
                        { p: "Add or rename the labels and **Save changes**. Without any direct relationship types, contacts cannot be linked to each other." },
                    ],
                },
                {
                    id: "query-builder",
                    title: "Query builder",
                    adminOnly: true,
                    lead: "Saved searches that power dashboard widgets and group mailings.",
                    blocks: [
                        { steps: ["Press **New query** and name it.", "Under **What are you searching?** choose **Contacts** or a **Data object**.", "Add the **Contact criteria** (and, for data objects, **Linked contact criteria**). You can choose which relationship the contact must have.", "Pick the **Result columns**, or leave them empty for the defaults.", "**Preview**, check the matches and save."] },
                        { warn: "Deleting a query stops every dashboard widget that uses it." },
                    ],
                },
                {
                    id: "import-export",
                    title: "Data import and export",
                    adminOnly: true,
                    lead: "Bring data in from a spreadsheet, or take a copy out. Large files run in the background.",
                    blocks: [
                        { h: "Import from CSV" },
                        { steps: ["Choose the **Import type**: **Contacts** or **Data objects** (and which object).", "Choose the CSV file. The first rows are read and a preview of the columns appears.", "Match each **CSV column** to a field with **Import into**, or choose *skip*.", "For data objects pick an **Identifying field** so existing items are updated instead of duplicated, or *None, always create new*. Choose how linked contacts are linked.", "Press **Start import**."] },
                        { h: "Export to CSV" },
                        { p: "Choose what to export (all contacts, notes and tasks, or a data object), which records (all, active or inactive) and press **Export to CSV**." },
                        { p: "Imports and exports run as jobs. **Recent jobs** lists them with their status and a **Download** link when an export is ready. You are also notified from the bell." },
                        { tip: "Try a handful of rows first. A wrong column mapping is quicker to fix before thousands of records are created." },
                    ],
                },
                {
                    id: "custom-tabs",
                    title: "Custom tabs",
                    adminOnly: true,
                    lead: "Add your own tab to every contact page.",
                    blocks: [
                        { p: "Press **Add tab**, give it a title and either the content to show or an address to embed. Embedded addresses must be **https** and the other site must allow being shown in a frame. The content is shown in a sandboxed frame: scripts are disabled. **Save changes** to publish." },
                    ],
                },
                {
                    id: "automations",
                    title: "Automations",
                    adminOnly: true,
                    lead: "Let the system do the routine work: when an item matches, take an action.",
                    blocks: [
                        { steps: ["Open **Automation** and press **New automation**.", "Name it and choose the **Data object** it watches.", "Choose **When to check**: as soon as an item is created or edited, every minute, hourly, or daily at midnight.", "Under **If this…** build the trigger condition. It runs once per item the first time it matches. Leave it empty to run for every item.", "Add one or more **actions** and fill in their details, then **Save automation**."] },
                        { h: "Actions" },
                        {
                            table: {
                                head: ["Action", "What it does"],
                                rows: [
                                    ["Send email", "E-mails each linked contact."],
                                    ["Send SMS", "Texts each linked contact."],
                                    ["Add tag / Remove tag", "Tags or untags each linked contact."],
                                    ["Create task", "Creates a task for each linked contact."],
                                    ["Update a field", "Changes a value on the item itself."],
                                    ["Call webhook", "Sends the item to one of your outbound webhook endpoints."],
                                ],
                            },
                        },
                        { p: "The automations list shows each one's schedule and status. You can pause and resume it, **Evaluate** it now, and open its run history. Messages from automations skip contacts who opted out." },
                        { note: "The platform sets how many automations each data object may have. At the limit, ask for more from the same page. Automations also pause if the platform switches the company's background services off." },
                        { warn: "Deleting an automation deletes its run history. Actions it already took are not undone." },
                    ],
                },
                {
                    id: "webhooks",
                    title: "Webhooks",
                    adminOnly: true,
                    lead: "Outbound HTTPS addresses that your automations can call.",
                    blocks: [
                        { p: "Press **Add endpoint**, name it and give the HTTPS address. In an automation choose **Call webhook** and pick the endpoint; the item is sent to it as a POST request. Deleting an endpoint makes every automation that calls it fail, so check first." },
                    ],
                },
                {
                    id: "email-templates",
                    title: "Email templates",
                    adminOnly: true,
                    lead: "Reusable messages that everyone can choose in the composer.",
                    blocks: [
                        { list: ["**New template** opens the editor: group, name, subject and body, with merge fields.", "**Preview** shows how it will read, **Duplicate** makes a copy to adapt, and templates can be deleted.", "Groups keep long lists tidy: people choose a **Template group** first in the composer."] },
                    ],
                },
                {
                    id: "forms",
                    title: "Form builder",
                    adminOnly: true,
                    lead: "Capture leads from your website straight into your contacts.",
                    blocks: [
                        { steps: ["Press **Create form**, name it, and add fields (short text, long text, drop-down, checkbox and more).", "Write the thank-you message and **Save form**.", "From the forms list copy the embed code and paste it into your page."] },
                        { p: "Three ways to embed: **JavaScript (recommended)**, which resizes itself; an **HTML iframe**; or a **Direct link** you can share. Each submission creates or updates a contact. A hidden trap field catches simple bots." },
                        { warn: "Deleting a form breaks every page that embeds it and deletes its past submissions." },
                    ],
                },
                {
                    id: "blast-progress",
                    title: "Blast progress",
                    adminOnly: true,
                    lead: "Is mail going out? See the whole queue of e-mails and texts.",
                    blocks: [
                        { p: "Shows the last 7 days of sending: e-mails and SMS **waiting** and **failed**, per user, refreshing every 15 seconds. Choose **All users** or one person. A growing *waiting* number that does not fall usually means sending is paused or blocked: see the troubleshooting section." },
                    ],
                },
                {
                    id: "support-emails",
                    title: "Internal support e-mails and sync domains",
                    adminOnly: true,
                    lead: "Two short lists that keep your own mail out of the way.",
                    blocks: [
                        { list: ["**Internal support emails**: addresses that belong to your own team. Add or remove them.", "**Email sync domains**: domains to exclude from e-mail sync, for example your own company domain."] },
                    ],
                },
                {
                    id: "twilio",
                    title: "Twilio and phone calls",
                    adminOnly: true,
                    lead: "Send texts through your own Twilio account, and decide who calls from which number.",
                    blocks: [
                        { h: "Twilio" },
                        { p: "Optional. Without it your company sends through the platform's account, when the platform offers one. To use your own, enter the **Account SID** and **Auth token** (on the front page of the Twilio console) and either a **Send from** number or a **Messaging Service SID**. The token is stored encrypted and never shown again. **Test the account** checks that Twilio accepts it. Delivery reports come back to the callback address on App details." },
                        { p: "**Remove details** returns you to the platform's account. Text messages stop until you save new details or the platform offers one." },
                        { h: "Texting numbers and the monthly limit" },
                        { p: "Your plan allows a number of **texting numbers** (1, 3, 5, 10 or 20) and a number of texts per month (100 up to 5,000). Under **Texting numbers** on this page choose the numbers your texts are sent from: pick one of the suggestions from your account or type one, then **Save texting numbers**. The first is the default; **Make default** changes it. Whoever writes a text sees a **Send from** list when you have more than one." },
                        { warn: "When the month's limit is used up, further texts are not sent and show as failed with an explanation. They are not retried. The limit starts again at the beginning of the next month." },
                        { h: "Phone call" },
                        { p: "The caller ID is the number a contact sees when someone calls from the CRM. When the platform lets companies set them, choose a caller ID for each user and **Save**. Numbers must be owned or verified in your Twilio account, and a **Verified** marker shows which are. When the platform manages caller IDs the page is read-only." },
                    ],
                },
                {
                    id: "keap",
                    title: "Keap sync",
                    adminOnly: true,
                    lead: "Keep your contacts in step with Keap.",
                    blocks: [
                        { steps: ["In Keap create a service account key or personal access token (Settings, then API).", "Paste it into **Your Keap key** and **Save Keap details**. It is stored encrypted and never shown again.", "Use **Test the key** to confirm Keap accepts it.", "Ask your platform administrator to switch Keap sync on for your company."] },
                        { p: "The page shows its **Status**, whether the platform has switched sync on, and when it last synced. **Sync now** queues a run. **Remove details** stops the sync until you save new ones." },
                    ],
                },
                {
                    id: "planning-tools",
                    title: "Boards, scheduler, price lists and tours",
                    adminOnly: true,
                    lead: "The planning tools you set up once and everyone uses.",
                    blocks: [
                        { list: ["**Workflow boards**: **Add board**, choose the data object and the field whose values become the columns. Deleting a board deletes only the board, never its records.", "**Scheduler**: **Add schedule** to choose which data object dates appear on the Schedules calendar.", "**Price list generator**: build a price list, then **Lock & publish** it to get a read-only share link. Unlock it to edit again, or duplicate it.", "**Tour manager**: schedule tours of contact visits, **Send emails** to those involved, and archive or restore tours."] },
                    ],
                },
                {
                    id: "platform-messages",
                    title: "Messages to the platform, and requests for more",
                    adminOnly: true,
                    lead: "Your line to the people who run OrvynCRM.",
                    blocks: [
                        { p: "Open **Message the platform** to write to the platform administrators. Each message starts a thread; replies stay in it and a badge shows a new reply. You can archive, restore or delete conversations on your side. Only administrators of your company can use it." },
                        { p: "Your plan has limits for users, data objects and automations per object. Where you hit one, the page shows a **Request more…** button. The request goes to the platform with the price it will cost, and the outcome appears on the same page." },
                    ],
                },
            ],
        },
        {
            title: "Reference",
            sections: [
                {
                    id: "shortcuts",
                    title: "Shortcuts",
                    lead: "A few keys that save clicks.",
                    blocks: [
                        {
                            table: {
                                head: ["Keys", "What happens"],
                                rows: [
                                    ["Ctrl+K / Cmd+K", "Open or close the command palette."],
                                    ["Esc", "Close the palette, a pop-up menu or a dialog."],
                                    ["Up / Down, Enter", "Choose and open a result in the palette."],
                                    ["Enter in a task box", "Add the task."],
                                    ["Tab", "Move between fields. The first Tab on a page reveals a **Skip to main content** link."],
                                ],
                            },
                        },
                    ],
                },
                {
                    id: "troubleshooting",
                    title: "Troubleshooting",
                    lead: "Quick answers to the things people most often ask.",
                    blocks: [
                        {
                            table: {
                                head: ["If…", "Try this"],
                                rows: [
                                    ["I cannot sign in", "Check the e-mail address. Use **Forgot password?** if needed. After several wrong attempts the sign-in pauses for a few minutes. If you are told the company is suspended or closed, contact your administrator."],
                                    ["A menu or button is missing", "Your role or permission template does not include it. Ask an administrator."],
                                    ["E-mails or texts stay queued", "Sending runs in the background. If nothing leaves, the platform may have paused your company's sending. Administrators can look at Blast progress and message the platform."],
                                    ["Texts are refused", "No Twilio account is set up for your company and the platform has none. Add your own under Twilio."],
                                    ["Texts fail with a message about the limit", "Your plan's texts for this month are used up. They start again next month, or choose a bigger plan under Billing & plan."],
                                    ["The Subscribe buttons are greyed out", "You cancelled your subscription. Ask the platform to let you subscribe again on the Subscription card; you are told when it is enabled."],
                                    ["I only see the Billing & plan page", "Your free trial has ended and no billing information (or, where the platform asks for it, no subscription) is on file. An administrator enters it or subscribes there and everything opens again."],
                                    ["A contact does not receive automation e-mails", "They may be opted out or on the suppression list. Check the Overview and Communication tabs."],
                                    ["I cannot add a user, object or automation", "You reached the limit of your plan. Use **Request more…**."],
                                    ["An upload fails", "The file is probably larger than the server accepts. Try a smaller file."],
                                    ["Dates look an hour off", "Check your timezone in User settings and the company timezone in App details."],
                                    ["The page looks out of date", "Reload the page. After an update from the platform, a fresh load fetches the newest version."],
                                ],
                            },
                        },
                        { tip: "Still stuck? Administrators use **Message the platform**. Other users ask their company administrator." },
                    ],
                },
                {
                    id: "glossary",
                    title: "Glossary",
                    lead: "Short definitions of the words used in the app.",
                    blocks: [
                        {
                            table: {
                                head: ["Term", "Meaning"],
                                rows: [
                                    ["Blast", "Messages sent to many contacts at once, such as Email all from a widget."],
                                    ["Callback URL", "An address a provider calls to tell your company what happened to a message."],
                                    ["Merge field", "A placeholder in a message that becomes the contact's own detail when it is sent."],
                                    ["Opt-out", "A contact's choice (or yours on their behalf) to stop receiving e-mail."],
                                    ["Permission template", "A named set of permissions you can give to several users."],
                                    ["Query", "A saved search over contacts or records."],
                                    ["Suppression list", "Addresses we no longer send to because of a bounce, complaint or unsubscribe."],
                                    ["Token", "A secret that lets a script act as you through the API."],
                                    ["Widget", "A dashboard card that shows a query's result."],
                                ],
                            },
                        },
                    ],
                },
            ],
        },
    ],
};
