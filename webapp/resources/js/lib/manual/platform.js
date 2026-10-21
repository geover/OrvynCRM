// The manual for platform administrators (everything under /platform). It is a different job from working inside a company, so it
// is a different manual. Drawn by Components/ManualViewer.vue. Keep it in step with the screens and their labels.

export default {
    groups: [
        {
            title: "Getting started",
            sections: [
                {
                    id: "platform-welcome",
                    title: "What the platform area is",
                    lead: "The control room for every company on this installation. Nothing here belongs to a single company.",
                    blocks: [
                        { p: "A **company** is a customer workspace with its own database, people and settings. The **platform area** sits above them: you create companies, set their limits, look after their health, answer them, and decide what the whole installation looks like and how it talks to the outside world." },
                        {
                            table: {
                                head: ["", "Platform area", "Company area"],
                                rows: [
                                    ["Who", "Platform administrators", "A company's users and administrators"],
                                    ["Scope", "All companies at once", "One company"],
                                    ["Address", "`/platform`", "The company's own pages"],
                                    ["Look", "The platform's colour and its own light or dark choice", "The company's logo, colour and sidebar"],
                                    ["Sees", "Counts, health, requests, messages", "Contacts, records, tasks and so on"],
                                ],
                            },
                        },
                        { note: "Platform administrators are not company users. Companies never see them in their user lists, and only the Administrators page can add, edit or demote them." },
                    ],
                },
                {
                    id: "platform-signin",
                    title: "Signing in and moving between areas",
                    lead: "You sign in like everyone else. Your e-mail decides where you land.",
                    blocks: [
                        { steps: ["Sign in with your e-mail and password on the normal sign-in page.", "If you belong to no company you go straight to **Companies** in the platform area. If you also belong to companies you are offered them, and your account menu has a **Platform** entry that brings you back.", "Opening the sign-in page again while you are signed in as a platform administrator simply sends you back to the platform."] },
                        { h: "Opening a company" },
                        { p: "On the Companies page **Open** enters a company as an administrator, to see what they see or to help them. Your presence there is a hidden *support profile*: it does not count against the company's user limit and does not appear in its user list. Any platform page you open next closes the company again, so its look and settings never leak into the platform." },
                        { tip: "Use **Users** on a company row to add or invite people for them without opening it at all." },
                    ],
                },
                {
                    id: "platform-layout",
                    title: "Finding your way",
                    lead: "A grouped menu on the left, with counters for what needs you.",
                    blocks: [
                        {
                            table: {
                                head: ["Group", "Menu entries"],
                                rows: [
                                    ["Overview", "Dashboard"],
                                    ["Manage", "Companies, Onboarding, Usage, Account standing, Administrators, Requests"],
                                    ["Communicate", "Messages, Tickets, Updates & news"],
                                    ["Operations", "Database viewer and Settings (with Sign-ups, Bot protection, Sign-in page, E-mail, SMS, Calls, Pricing, Background services and Inactive companies inside it) and Manual"],
                                ],
                            },
                        },
                        { h: "Tiles, search and layouts" },
                        { p: "The main pages (Companies, Onboarding, Account standing, Administrators, Requests, Tickets, Messages and Updates & news) open with a row of **tiles**: the numbers that matter, with a pulsing one when something needs you. A tile that looks like a button is also a **filter**. Under it are a **search box** and a **layout switch** with **List** (the table), **Detailed** (a card each, with everything) and **Boxes** (a grid). Your choice is remembered per page in this browser. Tickets has a **Board** and a **List**; Onboarding has the switch on both its Companies and E-mails tabs." },
                        { list: ["Amber counters mean *waiting for you*: Companies (abandoned ones to review), Requests (more users, objects or automations asked for) and Messages (unread conversations), Tickets (waiting for you), Onboarding (companies that need a hand) and Account standing (failed payments).", "At the foot of the menu you find the platform's own **theme** (light, dark or follow the device) and the dark style picker. It is separate from any company's choice.", "On a phone the menu opens from the button at the top left."] },
                    ],
                },
            ],
        },
        {
            title: "Running companies",
            sections: [
                {
                    id: "platform-dashboard",
                    title: "The dashboard",
                    lead: "What companies are doing, what their automations weigh, and what that costs the servers.",
                    blocks: [
                        { p: "Choose a **Period** (last 24 hours and longer) at the top. Numbers are collected by an hourly job; right after installing, press **Refresh data** to collect them now." },
                        { list: ["**Tiles**: companies, contacts, activities, automations, background jobs, worker time, failures and **Queue right now** (click it for the waiting jobs by kind and company).", "**Company activity**: new contacts and activity over time, and the **Most active companies**.", "**Heaviest automations**: which automations use the most worker time and fail the most.", "**Server and background workers**: who uses the workers, plus a live chart of jobs waiting and running per queue.", "**User limits**: how close each company is to the limits you gave it.", "**Impact by company**: new contacts, failed runs and automation worker time side by side."] },
                        { tip: "A company with high worker time and many failures is the first place to look when everything feels slow. Open its overview, then its background services." },
                    ],
                },
                {
                    id: "platform-companies",
                    title: "Companies",
                    lead: "The list of every company, and the place to create, change, open, pause or close one.",
                    blocks: [
                        { h: "Reading the list" },
                        {
                            table: {
                                head: ["Column", "Shows"],
                                rows: [
                                    ["Company", "Name (opens its overview) and its address."],
                                    ["Status", "**active**, **suspended** or **closed**, with the reason you gave under it, then the licence tier and, while it runs, the days left of its trial (*trial ended* in red when it has run out without billing information)."],
                                    ["Users", "Used of allowed, and an *asks for more* tag when a request is waiting."],
                                    ["Data", "Contacts, data objects and records (on wide screens)."],
                                    ["Last active", "How long since anyone used it, and when it was created."],
                                    ["Icons", "Users, Edit, Open, Suspend or Activate, Close. Hover for the name."],
                                ],
                            },
                        },
                        { p: "The chips above the table filter the list: **All**, **Active**, **Inactive**, **Trial ended** (no billing information after the trial), **Suspended** and **Closed**, each with its count. The link at the right shows when a company counts as inactive." },
                        { p: "At the top, tiles count **Companies**, **Active**, **On a trial** (and how many end within 14 days), **Inactive** and **Users** across every company; click Companies, Active or Inactive to filter. Use the box to find a company by name or address. The layout switch shows the table, a card per company (with its user meter, contacts, data objects and records) or a grid; the same row of buttons works in all three. *Open a company in* chooses between the pop-up window and the full page." },
                        { h: "Create a company" },
                        { steps: ["Press **New company**.", "Enter the **Company name**. Choose its **Licence tier** (its user limit follows the tier) and, if you like, **Start a free trial**; companies you create yourself have none unless you tick it. Optionally set **Users allowed** above what the tier includes, the data objects and automations per object it may have (empty means the default), and its **Slug**, the company's short name, which is otherwise made from the name.", "Enter the **First administrator**: name, e-mail and a password. Leave the password empty to e-mail them a link to choose their own.", "Press **Create company**. A database of its own is created, set up and filled with the starting data."] },
                        { h: "Change a company" },
                        { p: "**Edit** changes its name, its **Slug** (the company's short name: lowercase letters, numbers and hyphens, and unique. It is shown under the company's name and in the company chooser, it is what API logins send as `company=` when a person belongs to several companies, and what commands take as `--company=`. It does not name the database or files, so changing it never moves data, but anything still sending the old slug must use the new one), its **Licence tier**, the date its **trial ends** and its limits. **Users allowed** starts at what the tier includes (Tier 1: 5, Tier 2: 15, Tier 3: 50, Tier 4: 100, Tier 5: unlimited, which is fixed). You can raise it above that to give a company extra users without moving it to a bigger tier, but never below it. Choosing another tier fills in that tier's number, which you can then raise before saving. Clearing the trial date takes the trial away. Raising a limit takes effect at once. **Suspend** and **Close** are explained below." },
                        { h: "Suspend, close, activate" },
                        {
                            table: {
                                head: ["Status", "People in the company", "Background work", "Data"],
                                rows: [
                                    ["active", "Can sign in and use the API", "Runs", "Kept"],
                                    ["suspended", "Cannot sign in or use the API", "Left out", "Kept"],
                                    ["closed", "Cannot sign in or use the API (told it is closed)", "Left out", "Kept"],
                                ],
                            },
                        },
                        { p: "**Activate** brings a suspended or closed company back and restarts its inactivity clock. Closed is not deletion: nothing is removed." },
                        { warn: "Suspend and Close act immediately for everyone in the company. People signed in are cut off on their next request." },
                    ],
                },
                {
                    id: "platform-overview",
                    title: "A company's overview and its people",
                    lead: "Click a company's name for a full picture of what it holds and what it costs.",
                    blocks: [
                        { list: ["Counters for contacts, data objects, records, open and done tasks, form submissions, automations, e-mails, texts, saved queries, dashboard widgets, webhooks, price lists and attachments.", "**Activity, last 30 days**, **Records per data object**, and **Background work, last 30 days** (activity, new contacts, automation runs).", "**Background services** and **per data object**: what is switched on for this company, with the same switches as the Background services page.", "**Data objects and automations**: its usage against the limits, where you can set a different limit for one object."] },
                        { p: "Numbers are stored, not live. A line such as *Not counted yet* means you should press **Refresh numbers**." },
                        { h: "People of a company" },
                        { p: "**Users** on the list shows its people with role and status. **Add user** adds a person without opening the company: name, e-mail, role (User, Manager or Administrator) and a password, or leave it empty to invite them by e-mail. They count against its user limit; at the limit the button is disabled until you raise it with **Edit**." },
                    ],
                },
                {
                    id: "platform-inactive",
                    title: "Inactive companies",
                    lead: "Find companies nobody uses any more, and decide what happens to each.",
                    blocks: [
                        { p: "Each night the platform checks every active company. A company with **no activity for the number of days you chose** (see Settings, Inactive companies) is *flagged*. Activity means a person opened a page, signed in or changed something, or a visitor sent one of its forms. A tab left open does not count." },
                        { list: ["The company's administrators get an e-mail and a notice in the app, so a company that is only quiet can say it is still there by signing in. This can be switched off.", "Platform administrators who ticked **Inactive companies** under E-mail notifications get one e-mail listing the new ones.", "The Companies menu shows an amber counter, and the page shows a banner with **Review them**."] },
                        { h: "Deciding" },
                        { p: "Open the **Inactive** chip. For each flagged company choose:" },
                        {
                            table: {
                                head: ["Button", "Result"],
                                rows: [
                                    ["Keep", "Not abandoned after all. It is no longer flagged and the clock starts again today."],
                                    ["Suspend", "Stops sign-in and background work. Opens a dialog for a short reason and a tick box to e-mail its administrators."],
                                    ["Close", "Same effect with the status *closed*, for companies that have really gone."],
                                ],
                            },
                        },
                        { note: "Nothing is ever suspended, closed or deleted automatically. Someone using the company before the next nightly check clears its flag by itself." },
                    ],
                },
                {
                    id: "platform-tickets",
                    title: "Tickets",
                    lead: "Handle the problems the companies' administrators report, from open to solved.",
                    blocks: [
                        { p: "Open **Tickets**. Tiles at the top count **Needs us**, **Unassigned**, **Waiting for the company**, solved in 7 days and the oldest open one; the first three filter. The **Board** has one column per status: *Needs us*, *Waiting for the company*, *Resolved* and *Closed*, filling the window so each column scrolls by itself. **Drag a card** to another column to change its status: it tilts while you carry it, takes the colour of the column it is over, and scrolls the board sideways near the edge. Moving it to Resolved tells the company. Cards are sorted by urgency, carry the priority colour, an unread dot and the owner's initials. Switch to the **List** for a table with filters. Search, *Whose* and *Priority* apply to both." },
                        { p: "Click a ticket to read the conversation. Reply to the company, or tick **Private note** to write for the team only; the company never sees notes. Set the **owner**, **priority**, and **what it is about**, and see the page, browser and window size the person reported from. The first person to reply becomes the owner if nobody is." },
                        { p: "In **Messages**, press **Convert to ticket** when a conversation turns out to be an issue. The messages are copied into a new ticket, the company is told, and both sides keep a link between the two." },
                        { p: "Under **Settings** choose after how many days a resolved ticket closes by itself, and whether the company's administrators are e-mailed about replies. Each platform administrator chooses under *E-mail notifications* whether to be e-mailed about tickets." },
                    ],
                },
                {
                    id: "platform-onboarding",
                    title: "Onboarding",
                    lead: "Guide every new company to the system’s full potential with e-mails that follow what it has actually done, and see who needs a hand.",
                    blocks: [
                        { p: "Every company that **signs up** joins the onboarding series. A company created by hand can be added from the **Add a company to the series** box. The series sends e-mails to the company’s **owner** (its first administrator). What is sent depends on what the company has really done, not only on the date." },
                        { h: "The steps" },
                        { list: ["**Contacts**: at least three contacts.", "**Data**: a data object of its own, or an import that finished.", "**Message**: a first e-mail or text sent.", "**Automate**: a first automation.", "**Team**: a second person in the company.", "**Boards**: a workflow board or a form.", "**Brand**: a logo or colours of its own."] },
                        { p: "The **welcome** e-mail goes first, at once. Then, when the gap has passed, the company gets the e-mail for the first step it has **not** taken yet. A step it has taken is never nagged about. When every step is done, one last **all set** e-mail goes out and the series ends. The company is read again every 15 minutes." },
                        { h: "The Companies tab" },
                        { p: "Every company with a progress bar and a dot for each step, when it was last e-mailed and last active, and its status: **On track**, **Needs a hand**, **Paused**, **Stopped by the owner** (it used the *Stop these tips* link in an e-mail) or **Completed**. Click a company for its steps with dates, every e-mail sent to it (and any that failed), and your own note. From there you can:" },
                        {
                            table: {
                                head: ["Button", "Result"],
                                rows: [
                                    ["Send the next e-mail now", "Sends the e-mail that is next in line to the owner at once, whatever the gap and the weekly limit say."],
                                    ["Pause the e-mails", "Nothing more is sent until you resume. The company is still watched."],
                                    ["Check now", "Reads the company’s steps again now instead of at the next run."],
                                    ["Handled", "Sets the “needs a hand” flag aside for 3, 7 or 14 days. It comes back if the problem is still there afterwards."],
                                ],
                            },
                        },
                        { h: "Needs a hand" },
                        { p: "A company is flagged when **no new step** has been taken for a while, **nobody has signed in** for a while, its **trial ends soon** with little done, or a **payment failed**. The number of days for each is yours to set. You are told by a **badge** on the Onboarding menu and by **one e-mail a day at 8:00** that lists the companies, both of which you can switch off. Each administrator chooses whether to get the e-mail under *E-mail notifications*." },
                        { p: "Tiles show how many companies are in the series, how many **need a hand**, how many **completed**, the average progress and the e-mails sent in 7 days. On the Companies tab choose *List*, *Detailed* or *Boxes*; each shows the progress bar, the steps done and what comes next." },
                        { h: "The E-mails tab" },
                        { p: "Each e-mail has a subject, a preview line, an emoji and headline, a message written in the rich text editor, a button, and a *Did you know?* tip. **Edit and preview** shows it as the company will see it, with a made-up company, and **Send me a test** sends it to you. The merge fields (the owner’s first name, the company name, the steps done, the next step and more) are inserted with one click. Every e-mail also shows the company’s own progress, step by step, and ends with a **Stop these tips** link; an owner who uses it is left alone. Untick *Sent* to take an e-mail out of the series. Under **Send a copy (CC) to** you can add one or more addresses (separated by commas) that get the same e-mail, for your customer success team, say. The owner is never copied to themselves." },
                        { h: "The Settings tab" },
                        { p: "Switch the series on or off, choose the **days between two e-mails** (3 to start with) and the **most e-mails in any 7 days** (2), the thresholds for *needs a hand*, and how you are told. **Reply-to address** is where an owner’s reply goes: leave it empty and replies go to the first platform administrator (the page shows which address that is). Every e-mail also carries the one-click *Unsubscribe* header, so Gmail and Outlook show their own Unsubscribe button next to it." },
                        { tip: "A good first week: look at **Needs a hand** every morning, open the company, read what it was sent, and write to the owner from Messages. Put what you learn in the note so the next person knows." },
                    ],
                },
                {
                    id: "platform-usage",
                    title: "Usage",
                    lead: "Every company against what its tier allows: active users, texts this month and texting numbers, month by month.",
                    blocks: [
                        { p: "Open **Usage** in the menu. Each company shows three meters (red at the limit, amber from 80%) and a badge **At a limit** or **Near a limit**; the chips filter the list and the month menu shows earlier months. **Measure now** measures every company immediately." },
                        { list: ["It is measured every hour, and at once when a company's texts run out. Users show the most active users seen in the month; texts count from the 1st in each company's own time zone.", "Company administrators are e-mailed and get a bell notice at 80% and 100%. You get one e-mail when a company first reaches 100% (the **Requests** topic).", "The Companies list shows *near limit* or *at limit* beside the tier, and a company's overview lists its usage for the last six months.", "A company above a limit after moving to a smaller tier shows as at a limit: people are not removed, but nothing new can be added. Texting numbers beyond a smaller tier's allowance are dropped, keeping the first (the default)."] },
                        { p: "Texts sent by automations use the same count and the same monthly cap as texts sent by hand; there is no breakdown by source." },
                        { tip: "A company that keeps hitting a limit is a sales conversation: it needs the next tier." },
                    ],
                },
                {
                    id: "platform-standing",
                    title: "Account standing",
                    lead: "Where every company stands with paying, and a way to nudge the ones that should act.",
                    blocks: [
                        { p: "Open **Account standing** in the menu. At the top are the totals: how many companies are paid and up to date (with what they come to per billing period at the current prices), how many have a payment that failed (and what is at risk), how many are on a trial, and how many are invoiced or have no billing details. A red badge on the menu item counts the companies whose payment failed." },
                        {
                            table: {
                                head: ["Standing", "What it means"],
                                rows: [
                                    ["Invoice overdue", "A company you bill yourself has an invoice that is past its due date and not marked paid. The row says how many, how much and how late."],
                                    ["Payment failed", "Stripe could not collect the subscription (or an invoice failed and is still unpaid). The row says how much, how many attempts and when Stripe tries next."],
                                    ["Trial ended, not paying", "The trial is over and there is no subscription or, where the platform asks for it, no billing details. Only its billing page is open."],
                                    ["No billing details", "Not on a trial, not subscribed and no billing details on file."],
                                    ["Trial ending soon", "The trial ends within 14 days and the company is not paying yet."],
                                    ["Cancelled", "The subscription ended, or is set to end with this period."],
                                    ["On trial / Invoiced / Paid, up to date", "Nothing to do. *Invoiced* means billing details are on file and you bill the company yourself: the application does not track those invoices."],
                                ],
                            },
                        },
                        { p: "The four tiles at the top (**Paid**, **Payment failed**, **Trials** and **Invoiced by you**) are filters, and the failed and overdue ones pulse when there is something to chase. The layout switch shows the table, a card per company or a grid." },
                        { list: ["**Needs attention** (the default filter) lists the companies that should act, most urgent first. The other chips filter by one standing, and the box finds a company by name.", "**Remind** sends the company's administrators a notice in the app and an e-mail that says what they need to do for the standing they are in (update the card, subscribe, enter billing details). You can remind a company once a day.", "**Failed payments are followed up by themselves**: the company is reminded after 3 days and again, more firmly, after 7. You are e-mailed once (the **Requests** topic) if it is still unpaid after 7 days. Each step is sent once per failure.", "**Companies you invoice yourself**: on the company's overview, under *Invoices you issue*, **Add invoice** records the number, amount, invoice date and due date (it does not send anything to the company). Press **Mark paid** when the money arrives. When a due date passes without it, the company shows as *Invoice overdue*, you can **Remind** it, and the same 3 and 7 day follow-up as for failed payments runs. An invoice that is not yet due keeps the company as *Invoiced*, with the next due date.", "A company's **overview** has the same *Account standing* box with its **payment history**: each Stripe invoice, its amount and whether it was paid or failed, with a link to Stripe's invoice."] },
                        { warn: "Standing comes from Stripe through its webhook, so it is only as current as that connection. In Stripe, add the events `invoice.paid` and `invoice.payment_failed` to the webhook endpoint (Settings, Payments lists all of them); invoices from before that are not in the history." },
                    ],
                },
                {
                    id: "platform-requests",
                    title: "Requests for more",
                    lead: "Companies ask for more users, data objects, automations or texts. You answer.",
                    blocks: [
                        { steps: ["Open **Requests**. Filter by type; the chips show how many are **Waiting**.", "Each request shows the company, what it asks for and the price quoted to them when they asked.", "Press **Approve** to raise the limit at once, or **Decline**. The company sees the outcome on the page where it asked.", "Finished requests can be archived, restored, or deleted permanently."] },
                        { p: "A **Texts** request asks for a new total of texts per month. Approving it (you can enter another number) sets the company's *Extra texts per month* to the difference above its tier, so it appears on the company's Edit page and survives plan changes. Its meters, warnings and the Usage page update at once. Texts that already failed because the limit was reached are not sent again. There is no price quote for texts: you decide case by case. When you approve a texts request you choose **For how long**: *Every month* (the allowance stays until you change it) or *This month only* (a boost that ends by itself on the 1st, in the company's time zone; nothing has to run for that, and an earlier permanent extra is kept). A one-month boost can also be set by hand on a company's **Edit** page, under *Extra texts, this month only*; a number you change there counts for the current month." },
                        { p: "The page is at `/platform/requests` (the old `/platform/seat-requests` address forwards to it). Tiles show how many are **Waiting**, **Approved**, **Declined** and **Archived**, and each request has an icon for its type. Search by company or person, and pick *Detailed*, *List* or *Boxes* with the layout switch." },
                        { tip: "Set your prices first under Settings, Pricing, so the figure a company saw is the one you review." },
                    ],
                },
                {
                    id: "platform-admins",
                    title: "Platform administrators",
                    lead: "The people who manage every company.",
                    blocks: [
                        { list: ["**Add administrator**: name, e-mail and a password (new accounts only), or leave it empty to e-mail a link. If the e-mail already has an account it is promoted: its company profiles become hidden support profiles and it leaves the companies' user lists.", "**Edit** changes name or e-mail. **Reset password** e-mails a link.", "**Demote** returns a person to an ordinary user. You cannot demote yourself, so there is always someone left."] },
                        { p: "Tiles show how many administrators there are, who is **online now**, who is **inactive** and how many are protected by a second step or a passkey. Choose *List* (the table, with a row of details under each administrator), *Detailed* (a card with their photo, an online dot, how they sign in and the latest sign-ins) or *Boxes*." },
                        { warn: "Platform administrators can open and change any company. Add only people who should have that power." },
                    ],
                },
            ],
        },
        {
            title: "Communicating",
            sections: [
                {
                    id: "platform-messages",
                    title: "Messages",
                    lead: "Conversations with company administrators.",
                    blocks: [
                        { p: "A company administrator starts a thread from **Messages**; you answer from **Messages**. Tiles show what is unread, how many conversations there are and how many are archived (Unread filters the list). The conversation fills the window, so the reply box stays in view, and the reply box is a rich text editor. Replies stay in the thread and attachments such as images are supported." },
                        { list: ["Unread threads are marked and counted in the menu. **Mark unread** brings one back to your attention.", "**Archive** hides a finished conversation; an archived one must be restored before you can reply. **Delete permanently** removes the whole conversation.", "If the company deleted the conversation on its side you are told, but you still keep your copy."] },
                        { tip: "Each administrator chooses under E-mail notifications whether to be e-mailed about new messages. The menu badge always counts everything." },
                    ],
                },
                {
                    id: "platform-updates",
                    title: "Updates & news",
                    lead: "Tell everyone what is new, on the sign-in page and inside every company.",
                    blocks: [
                        { steps: ["Press **New update** and choose its **Kind**.", "Write a **Title** and the **Text**. A blank line starts a new paragraph, lines starting with `- ` make a list, and `**text**` is bold. **Preview** shows the result.", "Under **Show it** choose the sign-in page, *inside companies* (their What's new page), or both. **Pin to the top** keeps it first.", "Under **Publishing** publish now, schedule it for a time, keep it as a draft, or set a time to **Stop showing on**."] },
                        { p: "Tiles count what is **Live**, **Scheduled**, **Drafts** and **Archived**; click one to filter. Search finds an update by its title or text, and the layout switch shows *Detailed* cards, a *List* or *Boxes*. Each update shows an icon for its kind." },
                        { p: "Published updates can be unpublished, edited, or archived. Archived updates are hidden everywhere until restored. An update that is still showing must be unpublished before it can be archived." },
                    ],
                },
            ],
        },
        {
            title: "Settings",
            sections: [
                {
                    id: "platform-settings",
                    title: "The Settings overview",
                    lead: "Everything that is set for the whole platform, on one page.",
                    blocks: [
                        { p: "Settings opens an overview of cards grouped by purpose, each summarising its current state; click a card to change it. A side menu is shown on every settings page." },
                        {
                            table: {
                                head: ["Group", "Pages"],
                                rows: [
                                    ["Access", "Sign-ups, Sign-in security, Bot protection"],
                                    ["Appearance", "Sign-in page, Platform look"],
                                    ["Communication", "E-mail notifications"],
                                    ["Integrations", "E-mail server, SMS, Calls"],
                                    ["Billing", "Plans & trial, Payments, Pricing"],
                                    ["System", "Background services, Inactive companies"],
                                ],
                            },
                        },
                    ],
                },
                {
                    id: "platform-signups",
                    title: "Sign-ups",
                    lead: "Let people create their own workspace.",
                    blocks: [
                        { p: "Choose **Who can sign up**:" },
                        {
                            table: {
                                head: ["Mode", "Effect"],
                                rows: [
                                    ["Nobody", "The default. The *Create an account* link is hidden on the sign-in page."],
                                    ["Anyone", "Anyone can create a workspace once they have confirmed their e-mail address."],
                                    ["After approval", "People ask, confirm their e-mail, and wait until you approve."],
                                ],
                            },
                        },
                        { p: "People go through **ask, confirm e-mail, choose a password**. Nothing is created until they have confirmed. The list **Recent requests** shows the company, person, status and when they asked: *Waiting for e-mail*, *E-mail confirmed*, *Waiting for approval*, *Approved*, *Setting up*, *Workspace created*, *Declined* or *Link expired*. In approval mode press **Approve** or **Decline** on each." },
                        { h: "The whole flow, from the website to the first week" },
                        { steps: ["A visitor presses **Start your free trial** on the website (or **Create an account** on the sign-in page). Both lead to the CRM's own sign-up page, which is open only while sign-ups are switched on. Bot protection (reCAPTCHA) is checked here when it is on.", "They enter the **company name**, their **name** and **e-mail**. Nothing is created yet; they are sent an e-mail with a link and see a *Check your e-mail* page (they can ask for the link again).", "They open the link, which confirms the e-mail address, and choose a **password**. In *After approval* mode they now wait for you; in *Anyone* mode they carry on.", "The workspace is created: its own database, the person as its **owner** (the first administrator), the default limits and a **free trial** on Tier 1 (the length is set under Plans & trial). They are signed in and land on a **getting-started checklist**.", "The company appears under **Companies** and on the **Recent requests** list as *Workspace created*, and joins the **Onboarding** series."] },
                        { h: "What happens afterwards" },
                        { list: ["The owner receives the **welcome e-mail** at once, and then the onboarding e-mails that follow what the company has actually done (see Onboarding). You write them, and you can pause the series for one company.", "The company sees a trial banner with the days left, and gets reminders by e-mail as the trial nears its end. A company that does nothing shows up as *needing a hand* on the Onboarding page and in the daily e-mail.", "When the trial ends without billing information or a subscription, only its Billing & plan page stays open. It then appears under **Account standing** as *Trial ended*.", "A company that goes quiet is flagged by the daily inactive-companies check, and you decide to keep, suspend or close it."] },
                        { h: "Following it" },
                        { list: ["**Settings, Sign-ups** shows every request with its status, and is where you approve or decline in approval mode.", "**Companies** lists the new workspace, its trial and its users; **Onboarding** shows how far it has come.", "Tick **requests** (and **onboarding help**) under *E-mail notifications* to be told about each request and about companies that need a hand."] },
                        { note: "New workspaces start with the default limits; change them afterwards on the Companies page. Sign-up sends e-mail, so set up the E-mail server first. Tick **requests** under E-mail notifications to hear about each one." },
                    ],
                },
                {
                    id: "platform-security",
                    title: "Sign-in security",
                    lead: "Who must use two-step verification, whether passkeys are offered, and which accounts people can sign in with. Open Settings, then **Sign-in security**.",
                    blocks: [
                        { h: "Two-step verification" },
                        { list: ["**Optional for everybody**: each person turns it on in their own sign-in security page.", "**Required for platform administrators**, **for administrators** (yours and every company's) or **for everybody**: a person it applies to is taken to their security page on the next page they open and can do nothing else until they have set it up. A passkey counts as well.", "The page shows how many active people already use it. The API refuses to give a token to somebody who must set it up first, and asks for an `otp` code from the people who use it."] },
                        { note: "Everybody sets up their own: it is one per person and covers the platform and every company they open. Your own is under **Sign-in security** at the bottom of the platform menu. When somebody loses their phone and recovery codes, use **Reset 2-step** on the Administrators page or on a company's People page; they are told by e-mail." },
                        { h: "Passkeys" },
                        { p: "Tick **Offer passkeys** to let people sign in with a fingerprint, face, device PIN or security key. Only a public key is stored. Passkeys are tied to the address people sign in on: if you change that address, they add them again." },
                        { h: "Continue with Google, Microsoft, GitHub, single sign-on" },
                        { steps: ["Open the card of the provider, tick **Offer…** and copy the **callback address** into the provider's settings (each card says where).", "Paste the client ID and the client secret (the secret is stored encrypted and never shown again).", "Microsoft also needs your directory (tenant) ID; **Custom OpenID Connect** needs the provider's address, for example `https://acme.okta.com`, and a button label.", "Save. The button appears on the sign-in page as soon as the card says **On**."] },
                        { list: ["Nobody can create an account this way: a person is matched to an existing account by a **verified** e-mail address, and that provider's own id is remembered afterwards.", "Microsoft only vouches for addresses inside one directory, so a directory (tenant) must be named.", "A person who uses two-step verification still gives the code after a provider sign-in."] },
                    ],
                },
                {
                    id: "platform-bot",
                    title: "Bot protection (reCAPTCHA)",
                    lead: "An invisible Google check on the sign-in and sign-up pages.",
                    blocks: [
                        { steps: ["Open the reCAPTCHA admin console and create a site of type **reCAPTCHA v3 (score based)**.", "Add every address people sign in on (for local testing, such as `app.orvyncrm.test`) to the site's domains.", "Paste the **Site key** and **Secret key** here, tick the check box and save."] },
                        { list: ["**How strict** is the minimum score from 0.1 to 0.9. 0.5 is Google's suggested balance; higher may ask some real people to try again.", "The secret key is stored encrypted and never shown again. **Remove the saved key** deletes it.", "A refused person sees *We could not confirm that you are a person* and can retry. A refusal is never counted as a wrong password.", "If Google cannot be reached, people are let through and it is logged: an outage must not lock everybody out."] },
                        { note: "While it is on, the pages load Google's script and show a small *Protected by reCAPTCHA* note. Mention it in your privacy policy." },
                    ],
                },
                {
                    id: "platform-branding",
                    title: "The sign-in page",
                    lead: "The look of the sign-in, sign-up, company chooser and password pages, the same for everybody. Changes save by themselves. It does not change the platform area: that has its own look.",
                    blocks: [
                        { list: ["**Text, colour and theme**: product name, tagline, colour, and whether the pages are light, dark or follow the device (with a choice of dark styles).", "**Logo**: separate pictures for dark and light backgrounds, plus a monochrome version. SVG is accepted and cleaned of scripts. Companies without a logo of their own inherit this one.", "**Favicon** and **Background**: an image or a short video (MP4 or WebM, up to 25 MB) behind the sign-in page. A built-in one is used until you upload your own.", "**Footer of the sign-in page**: the copyright line, links to Privacy, Legal Policies, Terms of Use and Contact, and a business address. A link only shows when its address is filled in."] },
                        { warn: "Large files need the server to accept them. If an upload fails with a message naming a limit, ask your server administrator to raise `upload_max_filesize` and `post_max_size`." },
                    ],
                },
                {
                    id: "platform-website",
                    title: "Website",
                    lead: "What the public website says that is decided here.",
                    blocks: [
                        { p: "Under **Settings, Website** you set the **contact e-mail**, the **business address**, the **legal entity** and the two dates of the Privacy Policy and Terms, and the **demo form** address. The website reads them from a public address shown on the page when it is **built**; anything you leave empty keeps the website's own default from its `.env` file, and so does everything if this application cannot be reached while the site is built." },
                        { list: ["**Build hook** (optional): a Netlify, Cloudflare Pages or Vercel address that builds the site when it is called. With one saved, a change here rebuilds the website by itself, and **Rebuild the website now** does it on demand. It must be a public https address, it is stored encrypted and it is never shown again. The last request and its result are shown beside the button.", "Without a hook the website picks the change up the next time it is built.", "The list under *What the website reads* shows what the website will use right now: a field showing *not set* keeps its default."] },
                        { note: "These details are printed on a public site, so type only what you want everyone to see." },
                    ],
                },
                {
                    id: "platform-look",
                    title: "Platform look",
                    lead: "How the platform area looks to you and the other platform administrators. Separate from the sign-in page.",
                    blocks: [
                        { list: ["**Name:** shown at the top of the menu. Empty follows the sign-in page's name.", "**Colour:** the accent colour of this area's buttons, highlights and active menu items. Pick a preset or type a colour such as `#059669`. Empty follows the sign-in page's colour; **Use the sign-in colour** clears it.", "**Logo mark:** the small picture beside the name at the top of the menu (PNG, JPG, WebP or SVG, square works best). **Use the built-in one** goes back.", "**Favicon:** the icon in the browser tab while you are in the platform area. Empty follows the sign-in page's favicon."] },
                        { p: "Changes save by themselves. Until you set something here, this area looks like the sign-in page, as it always did; once you set a value, changing the sign-in page no longer changes it." },
                        { note: "The light or dark theme of the platform area is your own choice, in the footer of the left menu, remembered in your browser. The sign-in page's theme (Sign-in page settings) only applies to the sign-in pages." },
                    ],
                },
                {
                    id: "platform-notifications",
                    title: "E-mail notifications",
                    lead: "Each administrator chooses what they want to be e-mailed about.",
                    blocks: [
                        {
                            table: {
                                head: ["Topic", "You are told when"],
                                rows: [
                                    ["Requests", "A company asks for more users, data objects or automations."],
                                    ["Messages", "A company administrator starts or answers a conversation."],
                                    ["Failed automations", "A company's automation gave up or an action failed. At most one e-mail per automation per hour. Off until you tick it."],
                                    ["Inactive companies", "The nightly check flags companies that look abandoned."],
                                ],
                            },
                        },
                        { p: "**Send to** lets you use a different address from your own; leave it empty to use yours. The inbox and menu badges always show everything, whatever you tick." },
                    ],
                },
                {
                    id: "platform-mail",
                    title: "E-mail server",
                    lead: "The server every e-mail goes through: sign-up, invitations, notices, and companies' own messages.",
                    blocks: [
                        { p: "By default the application uses the server named in its `.env` file. Tick **Use this e-mail server instead of the one in .env** to set your own here, or **Use SendGrid**. Fill in the host, port, security, username, password and the sender address and name. Empty fields fall back to `.env` (for SendGrid: `MAIL_HOST=smtp.sendgrid.net`, `MAIL_PORT=587`, `MAIL_USERNAME=apikey` and the API key as `MAIL_PASSWORD`). The password is encrypted and never shown again. **Reset to .env** forgets everything saved here at once and goes back to the `.env` server (the SendGrid event settings below are not touched)." },
                        { tip: "To try e-mail on your own computer, Laragon's Mailpit catches everything and sends nothing out: host `127.0.0.1`, port `1025`, security *none*, no username or password. Open Mailpit in the browser to read the messages." },
                        { steps: ["Save the settings.", "Press **Send a test e-mail**, choose where to send it and check it arrives. If the server refuses it, you see its own message."] },
                        { h: "SendGrid event reports" },
                        { p: "If the server is SendGrid, give SendGrid the **Event webhook address** shown here (SendGrid: Settings, Mail Settings, Event Webhook) so bounces, spam reports, unsubscribes and clicks reach the right company. Each e-mail carries its company, so companies need no address of their own. Paste the **Verification key** and turn on *Signed Event Webhook* for extra safety (or set `SENDGRID_WEBHOOK_PUBLIC_KEY` in `.env` as the default; a key saved here replaces it). **Regenerate** gives a new address and the old one stops working." },
                    ],
                },
                {
                    id: "platform-sms",
                    title: "SMS (the platform's Twilio account)",
                    lead: "The account companies use for text messages when they have none of their own.",
                    blocks: [
                        { steps: ["Tick the switch that lets companies without their own account use this one.", "Enter the **Account SID**, the **Auth token** and either a **Send from** number or a **Messaging Service SID**. With a messaging service Twilio picks the number.", "Save, then **Send a test text** to a phone you control."] },
                        { p: "The `.env` file can hold the defaults (`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`, `TWILIO_MESSAGING_SERVICE_SID`): with a complete account there, texting is on until you save something here, a field left empty here uses the `.env` value, and anything you save replaces it. A company that sets up its own Twilio account keeps using that one. Delivery reports return to each company's own callback address, so the status lands on the right message. With nothing set up, the SMS buttons tell the person to ask you." },
                    ],
                },
                {
                    id: "platform-calls",
                    title: "Calls and caller IDs",
                    lead: "The number a contact sees when somebody calls from the CRM.",
                    blocks: [
                        { list: ["**Default caller ID**: used for everyone who has none of their own. Leave it empty for none.", "**Company administrators can set their users' caller IDs**: switch it off and only the default is used; every company's Phone call page becomes read-only.", "**Numbers on the platform Twilio account**: set up SMS first. Its numbers and verified caller IDs appear here; tick the ones companies without their own Twilio account may choose from. They never see the others.", "**Verify another number** starts a Twilio verification. You can rename or remove numbers."] },
                        { note: "A caller ID must be a number the account owns or has verified, or Twilio refuses the call." },
                    ],
                },
                {
                    id: "platform-plans",
                    title: "Plans & trial",
                    lead: "The five licence tiers, the free trial for new sign-ups, and the price of each tier.",
                    blocks: [
                        {
                            table: {
                                head: ["Tier", "Active users", "Texts per month", "Texting numbers"],
                                rows: [["Tier 1", "5", "100", "1"], ["Tier 2", "15", "500", "3"], ["Tier 3", "50", "1,000", "5"], ["Tier 4", "100", "3,000", "10"], ["Tier 5", "Unlimited", "5,000", "20"]],
                            },
                        },
                        { p: "The table is editable. For each tier you set its **name**, the **active users** it includes (only the top tier can be *Unlimited*), the **texts per month**, the **texting numbers**, and its **price** (per month or per year, in the currency chosen on the Pricing page; a tier without a price shows *Contact us* to companies). Above it you set the **length of the free trial**, in months (1 to 24, three by default). The tiers ship with 5, 15, 50, 100 and unlimited users, 100 to 5,000 texts and 1 to 20 numbers; **Back to the shipped tiers** restores that." },
                        { h: "Changing what a tier includes" },
                        { list: ["A bigger tier cannot include less than a smaller one, and tier names must differ. A refused form changes nothing.", "While you edit, **What saving would do** lists the companies that would be above a limit, before you save. Nobody is removed: such a company is simply at a limit until it frees something or moves up.", "Saving reaches **every company on that tier at once**: its users follow (a company that has extra users keeps its extra), its texts follow, and texting numbers beyond the new allowance are dropped, the default first. A renamed tier is renamed on Stripe too.", "When a price changes and Stripe is switched on, the confirmation window has a **Stripe** option (on by default) that also creates the new price on Stripe. The Stripe mode (test or live) is shown there and beside the tiers, and each tier says whether its price is *on Stripe*, *not on Stripe yet* or *Stripe has the old price*. With a **live** key you must tick a box to confirm before anything is saved. If Stripe refuses, the price is still saved and a warning tells you to press **Create or update the Stripe prices** on Payments.", "There is no way to change only new companies: a tier is the same for everyone on it. To give one company more, use the extras below."] },
                        { h: "Confirming a change, and telling people" },
                        { p: "**Save plans** (and **Back to the shipped tiers**) always asks first. The confirmation lists exactly what changes, tier by tier (old value crossed out, new value after), how many companies are on each changed tier, and any company that would be above a limit. Changing only the trial length saves without asking." },
                        { list: ["**The other platform administrators:** one e-mail each (the person saving is not e-mailed). Ticked by default.", "**The administrators of the companies on the changed tiers:** a notice in the app and an e-mail each, saying what their plan now includes, only for companies on a tier that changed. Ticked by default.", "**A note to add** appears when you tell anyone, and is included in what they receive.", "Nothing is sent when nothing changed, or when you untick both.", "A change of **price** is always confirmed too. If you have Stripe on, the same window carries the Stripe option (see Payments)."] },
                        { h: "Extras for one company" },
                        { p: "On **Edit** (Companies page) a company can have **Users allowed** above its tier, and **Extra texts per month** and **Extra texting numbers** on top of what its tier includes. The company's meters and limits include the extras and say so, and they survive changes to the tier. Lowering the extra numbers drops numbers beyond the new allowance." },
                        { h: "How the trial works" },
                        { list: ["A company created through **sign-up** starts a trial on Tier 1 that ends after the chosen number of months. Companies you create yourself have none unless you tick *Start a free trial*; companies that existed before are never forced.", "The company's administrators are reminded 14 days, 7 days and 1 day before the end, and told when it has ended. You are e-mailed once when it ends (the **Requests** topic).", "When it has ended without billing information, everybody in that company can only open its billing page until an administrator enters the details. Nothing is deleted. Find such companies with the **Trial ended** chip.", "To give a company more time, open **Edit** on the Companies page and move its trial date forward."] },
                        { h: "Tiers on a company" },
                        { p: "Moving a company to a tier (Edit, or the company choosing it on its Billing & plan page) sets its user limit to the tier's. A company can only move to a smaller tier when its active users and texting numbers already fit. You are e-mailed when a company changes tier or enters billing information, and the company's overview page shows its tier, trial, usage and billing details." },
                        { p: "On the Companies page, **Edit** has a **Licence tier**. Changing it always opens a window that explains the change before anything happens: the limits before and after, what the company uses today (with a warning if it is above the new limits), and what happens to its billing. Cancel leaves the company as it was." },
                        { list: ["**Not paying through Stripe** (invoiced, on its trial, or no subscription): only its limits change. Nothing is sent to Stripe; change the invoice yourself if you invoice it.", "**Paying through a running Stripe subscription**: you choose. *Move the subscription too, and settle the difference now* (recommended) switches Stripe to the new tier's price and charges or credits the prorated difference for the rest of the period. *Move it from the next renewal* changes the price without charging or crediting now. *Change the limits only* is a gift: no charge, the subscription and the card stay as they are, and Stripe events do not undo it until the subscription itself is changed.", "If Stripe refuses, nothing is changed (not even the other fields in the form) and its message is shown. A tier with no Stripe price yet cannot be billed through Stripe: create the price on Settings, Payments first.", "The company's administrators are told by default (a notice and an e-mail) what their plan now includes and what it means for their subscription; untick it in the window to stay quiet."] },
                        { note: "Card details are never collected here: with Payments (Stripe) switched on, companies pay on Stripe's own pages; without it, the billing information is what you need to invoice." },
                    ],
                },
                {
                    id: "platform-payments",
                    title: "Payments (Stripe)",
                    lead: "Let companies subscribe to a tier and pay by card on Stripe. Card details are typed on Stripe's own pages and never reach this application.",
                    blocks: [
                        { p: "Stripe was chosen because it offers hosted payment pages (so card data never touches your servers), recurring subscriptions with free trials, a ready-made customer portal, and a test mode. It is called through its web API, so nothing extra is installed." },
                        { h: "Set it up" },
                        { steps: ["In Stripe, copy a **secret key** (Developers, API keys). Use a test key (`sk_test_…`) first.", "Under Settings, **Payments** paste it, tick **Let companies pay by card through Stripe**, **Save**, then **Test the key**.", "In Stripe add a webhook endpoint with the address shown on the page and select the five events listed there. Paste its **signing secret** (`whsec_…`) here and save. The address must be reachable from the internet.", "Set the tier prices on Plans & trial, then press **Create or update the Stripe prices**. Saving a changed price on Plans & trial does this for you; press it only to retry. Companies already subscribed keep the price they signed up for until you move them (below)."] },
                        { h: "What companies see" },
                        { list: ["On their Billing & plan page the plan buttons say **Subscribe to…** and lead to Stripe. A running trial keeps running: the card is taken now and the first charge comes when the trial ends.", "Once subscribed they can change plan (Stripe prorates), and open Stripe's portal to change the card, see invoices or cancel.", "A tier without a price is not sold online: the company is told to ask you."] },
                        { h: "Require a payment method" },
                        { p: "**After the free trial, require a subscription** decides what a company whose trial has ended must have. Off (the default): its billing information is enough and you invoice it yourself. On: it can only open its billing page until it has subscribed. A payment that is late (Stripe retries it) still counts as subscribed." },
                        { h: "Cancelling a subscription" },
                        { p: "Open the company's overview: the **Subscription** row on the Plan & billing card shows what is possible." },
                        { list: ["**Cancel subscription…** (while it runs): choose *at the end of the paid period* (the company keeps its plan until then) or *now* (back to Tier 1 at once; nothing is refunded automatically, do that in Stripe), add a reason the company sees, and decide whether to e-mail its administrators.", "**Withdraw the cancellation** (after a period-end cancellation, before it takes effect): the subscription carries on and the company is told. **End it now…** turns it into an immediate cancellation.", "A company can also cancel by itself on its Billing & plan page; you are e-mailed (the **Requests** topic) and see who cancelled, when it ends and the reason.", "When a subscription has ended the company is moved to Tier 1 (its user limit follows) and nothing is deleted."] },
                        { h: "Subscribing again" },
                        { p: "A company whose subscription has ended cannot subscribe again by itself. It presses **Ask to subscribe again** and the request appears at the top of **Requests** (under *Subscribing again*, counted in the menu badge). **Enable** lets it subscribe: its Subscribe buttons come back and its administrators are told, with your note. **Decline** tells it you cannot do it now. You can also enable a company without a request with **Allow subscribing again** on its overview." },
                        { h: "Keeping track" },
                        { list: ["The Companies list shows *paying*, *subscribed*, *payment late* or *cancelled* beside the tier, and the company's overview shows Stripe's status, the card brand and last four digits, and the renewal date.", "When a payment fails, the company's administrators and you (the **Requests** topic) are e-mailed once.", "Stripe is the source of truth: everything is updated from its webhooks, so a company that pays or cancels in Stripe is reflected here within seconds."] },
                        { list: ["**Subscribers on an old price** (Payments): press **Check subscribers** to list the companies still charged an older price than their tier now has. **Move** switches them to the current price from their next renewal; nothing is charged or credited now, and you can tell their administrators."] },
                        { warn: "Test keys and live keys are separate worlds in Stripe, with their own prices and webhook. When you go live, enter the live key and signing secret, then create the prices again." },
                    ],
                },
                {
                    id: "platform-pricing",
                    title: "Pricing",
                    lead: "What an additional user, data object or automation costs.",
                    blocks: [
                        { p: "Set the **Currency** and the **Charged** period once, then the **Price of each addition**. A price left empty shows no price. A short **Note** is shown with every price. Companies see the prices, and the total, when they request more; the quote is stored with the request, so the figure they saw is the one you review. A preview shows what they will see." },
                        { note: "These prices are quotes for extra users, data objects and automations. The price of each licence tier is on Plans & trial, and paying by card is set up under Payments." },
                    ],
                },
                {
                    id: "platform-services",
                    title: "Background services",
                    lead: "What runs in the background for each company, and the switches to pause it.",
                    blocks: [
                        { p: "The **Scheduled work** table on this page lists every job the scheduler runs: what it does, its schedule, when it last ran (and whether it failed), how long it took and when it runs next. It also says whether the scheduler and the queue worker are running. A job shows no last run until it has run once while the scheduler is up." },
                        { p: "Three jobs can be changed with **Change**: measuring usage (every 15 or 30 minutes, hourly, every 2 or 6 hours, or daily), the trial reminders and the check for inactive companies (daily at a time you choose, or every 6 hours). **Back to the usual** returns to the default. A change is used by the scheduler from its next minute and nothing needs restarting. Times use the server's time zone, shown beside the table. The one-minute round that hands every company its due work and the housekeeping jobs are fixed, because slowing them would delay messages and automations." },
                        { warn: "Running a daily job less often than once a day is not offered. If the scheduler is stopped at the moment a daily job is due, that run is skipped until the next one." },
                        { p: "A red banner appears at the top of every platform page when the scheduler or the queue worker has been silent for a few minutes (3 for the scheduler, 5 for the worker), and platform administrators are e-mailed once per outage (the **Requests** topic). The banner only appears once someone has the platform open, and a process that has never run since installation shows the banner without an e-mail." },
                        { p: "Workers and the scheduler are server processes (Supervisor or Docker); starting and stopping them is done on the server. This page switches work off for **one company at a time** without touching them. Due work simply waits and is picked up when you switch it back on." },
                        { list: ["At the top: the **queue driver**, how many jobs are **waiting and running**, and **failed jobs**.", "One row per company with a switch for each service (for example messages, automations, task digest, Keap sync, housekeeping). Some switches wait for the company's details, such as Keap.", "**Run now** runs a company's scheduled work immediately. **Pause all** and **Resume all** act on that company's services, and **Stop for all** or **Start for all** act on one service across companies.", "Per data object switches for automations, e-mail and SMS sending and the overdue-task digest are on the company's overview."] },
                        { note: "Companies are independent: switching a service off for one never affects another, and each company's work is queued and limited on its own." },
                    ],
                },
                {
                    id: "platform-inactivity",
                    title: "Inactive companies (the check)",
                    lead: "How long a quiet company can stay quiet before it is flagged.",
                    blocks: [
                        { list: ["**Look for companies that are not used any more**: switch the nightly check on or off.", "**Days**: between 7 and 365, with quick choices (14, 30, 60, 90, 180).", "**Tell the company's administrators**: send them the e-mail and in-app notice. You are always told, by e-mail if you ticked that topic.", "**Right now**: the companies that would be flagged today, and **Check now** to run the check immediately."] },
                        { p: "The decision about each flagged company is made on the Companies page (see Inactive companies under Running companies). Existing companies start counting from the day this feature was installed." },
                    ],
                },
            ],
        },
        {
            title: "Reference",
            sections: [
                {
                    id: "platform-routine",
                    title: "A routine that works",
                    lead: "A short checklist for a healthy platform.",
                    blocks: [
                        { h: "Every day" },
                        { list: ["Look at the counters in the menu: **Requests**, **Messages**, and the amber one on **Companies**.", "Glance at the **Dashboard**: failures and the queue should be quiet."] },
                        { h: "Every week" },
                        { list: ["Review the **Inactive** companies and keep, suspend or close each.", "Check **Heaviest automations** for runaway rules and talk to the company.", "Post an **Update** for anything companies will notice."] },
                        { h: "After a release" },
                        { list: ["Server side: `php artisan migrate --force` and `php artisan tenants:migrate --force`, then `php artisan queue:restart`.", "Tell people what changed under Updates & news."] },
                    ],
                },
                {
                    id: "platform-troubleshooting",
                    title: "Troubleshooting",
                    lead: "What to check when something does not behave.",
                    blocks: [
                        {
                            table: {
                                head: ["If…", "Try this"],
                                rows: [
                                    ["A company says nothing is sent", "Open Background services and check that its Messages switch is on and the queue is moving. Then check the E-mail server test."],
                                    ["Nothing at all is sent", "Run **Send a test e-mail**. A refusal shows the server's own message. Check the queue workers are running on the server."],
                                    ["Texts fail", "Check the SMS test, and that the company has its own account or the platform's is switched on and has a number."],
                                    ["Sign-up e-mails do not arrive", "The E-mail server is not set up, or the address is suppressed. Test it, and look at the request status in Sign-ups."],
                                    ["People cannot sign in with reCAPTCHA on", "The site's domains must include the address in use, and the keys must be the v3 pair. Switch it off to recover."],
                                    ["A company cannot add a user", "It reached its limit. Raise it with Edit, or approve its request."],
                                    ["A company looks suspended by mistake", "Companies, **Activate**. The reason you gave is shown under its status."],
                                    ["An upload fails with a size message", "Raise `upload_max_filesize` and `post_max_size` on the server."],
                                    ["Dashboard shows no data", "Press **Refresh data**: numbers are collected hourly."],
                                ],
                            },
                        },
                    ],
                },
                {
                    id: "platform-glossary",
                    title: "Glossary",
                    lead: "The platform's own words.",
                    blocks: [
                        {
                            table: {
                                head: ["Term", "Meaning"],
                                rows: [
                                    ["Company (tenant)", "A customer workspace with its own database."],
                                    ["Platform administrator", "A person who manages every company. Not a company user."],
                                    ["Support profile", "The hidden profile you get inside a company when you open it. It does not count against limits."],
                                    ["Seat", "One allowed active user in a company."],
                                    ["Flagged", "Marked inactive by the nightly check, awaiting your decision."],
                                    ["Queue / worker", "Jobs wait in the queue and background workers carry them out."],
                                    ["Scheduler", "The once-a-minute process that hands each company its due work."],
                                    ["Callback / webhook", "An address another service calls to report what happened."],
                                ],
                            },
                        },
                    ],
                },
            ],
        },
    ],
};
