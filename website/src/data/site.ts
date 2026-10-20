/**
 * Everything the pages say, in one place. Change the words here; the pages only lay them out.
 * The facts about the product come from the OrvynCRM README: keep them true.
 */

import { remoteSettings } from '../lib/remote';

// What the platform decides (CRM: Settings > Website). Anything it has not set keeps the default from .env below.
const remote = await remoteSettings();

export const site = {
    name: 'OrvynCRM',
    tagline: 'Build. Connect. Automate.',
    description: 'OrvynCRM is a CRM that adapts to your business: your own record types, boards, tabs and pages, automations that run by themselves, and e-mail and SMS built in.',
    // Where the product lives. Set PUBLIC_APP_URL in .env.
    appUrl: (import.meta.env.PUBLIC_APP_URL || 'http://localhost:8000').replace(/\/$/, ''),
    // Where the demo form posts. Empty: the form falls back to an e-mail to contactEmail.
    demoFormUrl: remote.demo_form_url || import.meta.env.PUBLIC_DEMO_FORM_URL || '',
    // Where "Create an account" leads: the CRM's own sign-up page (open only while the platform has switched sign-ups on).
    get signupUrl() { return `${this.appUrl}/signup`; },
    contactEmail: remote.contact_email || import.meta.env.PUBLIC_CONTACT_EMAIL || 'hello@example.com',
};

export type NavItem = { href: string; label: string; text?: string; children?: { href: string; label: string; text: string; icon: string }[] };

/** The header menu: "Product" opens a small panel of its pages. */
export const nav: NavItem[] = [
    { href: '/features', label: 'Product', children: [
        { href: '/features', label: 'Features', text: 'Contacts, data objects, automations, messages and planning.', icon: 'boxes' },
        { href: '/integrations', label: 'Integrations', text: 'Keap, Twilio, SendGrid, any SMTP server and webhooks.', icon: 'link' },
        { href: '/security', label: 'Security', text: 'Separate databases, encrypted secrets and clear permissions.', icon: 'shield' },
    ] },
    { href: '/why-orvyncrm', label: 'Why OrvynCRM' },
    { href: '/pricing', label: 'Pricing' },
    { href: '/docs', label: 'Docs' },
];

/** What the product connects to. Names are shown as plain text, not as partner logos. */
export const integrations = [
    { name: 'Keap', kind: 'Contact sync', text: 'Pull your contacts from Keap into OrvynCRM on a schedule, using your own key.', icon: 'users' },
    { name: 'Twilio', kind: 'Text messages and calls', text: 'Send and track SMS through the shared account, or connect your own Twilio account and numbers.', icon: 'chat' },
    { name: 'SendGrid', kind: 'E-mail delivery', text: 'Send through SendGrid and get bounces, spam reports, unsubscribes and clicks back.', icon: 'send' },
    { name: 'Any SMTP server', kind: 'E-mail delivery', text: 'Use the mail server you already have. Set it once; every company can send through it.', icon: 'server' },
    { name: 'Webhooks', kind: 'Anything else', text: 'Let an automation call another system over HTTPS, and receive reports from your providers.', icon: 'link' },
    { name: 'CSV', kind: 'Import and export', text: 'Bring your contacts in from a spreadsheet and take your data out again whenever you like.', icon: 'table' },
];

/** Plain statements about how the product handles data. Each one is true of the product today. */
export const securityPoints = [
    { icon: 'boxes', title: 'A database of its own for every company', text: 'Records are not stored side by side with another company\'s. Each workspace has its own people, data model and database.' },
    { icon: 'shield', title: 'Secrets stay encrypted', text: 'Integration credentials, API tokens and mail passwords are encrypted when stored and are never shown again after you save them.' },
    { icon: 'key', title: 'Strong sign-in, your way', text: 'Two-step verification, passkeys, and sign-in with Google, Microsoft, GitHub or your own provider. Can be required of administrators.' },
    { icon: 'users', title: 'Permissions that match your team', text: 'Permission templates decide who can see and do what. Administrators control who is in the workspace.' },
    { icon: 'calendar', title: 'A record of what happened', text: 'Records keep their history, and every automation run and message delivery is written down. Administrators also see when each person last signed in and how long they were here.' },
    { icon: 'shield', title: 'Protected in the browser and at sign-in', text: 'Every page carries a content security policy and the usual browser protections. Sign-in is rate limited, can be protected by reCAPTCHA, and a password reset signs out every API token.' },
    { icon: 'mail', title: 'Safe sending', text: 'Every bulk e-mail carries an unsubscribe link, and bounces, spam reports and unsubscribes are recorded and respected. Sending is rate limited to protect your reputation.' },
    { icon: 'link', title: 'Your data goes where you say', text: 'Nothing leaves the workspace except through the integrations and webhooks you switch on. Export your data at any time.' },
];


export type Reason = { icon: string; title: string; text: string; points: string[] };

/** "Why OrvynCRM": each reason is something the product really does (see the README). No made-up numbers or claims about other products. */
export const reasons: Reason[] = [
    { icon: 'boxes', title: 'It fits your business, not the other way round',
        text: 'Most CRMs make you describe your business in their words. OrvynCRM lets you create the record types and fields your business actually has, and shows them on every contact.',
        points: ['Create deals, properties, subscriptions, tours or anything else', 'Your fields, your boards, and your own tabs and pages', 'Change it later without a consultant or a developer'] },
    { icon: 'zap', title: 'Follow-ups that happen, and a record that proves it',
        text: 'Automations send the e-mail, text the contact, create the task or call another system. Every run is written down, so you never wonder whether something went out.',
        points: ['Conditions and actions per record type', 'Run history with the result of each step', 'Told while you build if e-mail or SMS is not set up, so nothing silently fails'] },
    { icon: 'mail', title: 'Talk to people where they read',
        text: 'E-mail and SMS are part of the CRM, not a separate tool to keep in sync. Templates, tracking and delivery reports sit next to the contact they belong to.',
        points: ['One composer for e-mail and text', 'Opens, clicks, bounces and unsubscribes tracked', 'Use the shared account, or bring your own Twilio'] },
    { icon: 'shield', title: 'Your data stays yours, and apart',
        text: 'Every company has its own database, its own people and its own data model. One company can never see another\'s records.',
        points: ['A separate database for every company', 'Permission templates decide who sees and does what', 'Your own logo, colours and time zone'] },
    { icon: 'plans', title: 'Clear limits, no surprises',
        text: 'You always see how much you use: users, record types, automations and texts. Need more? Ask inside the app and see the price before you confirm.',
        points: ['"3 of 5" meters for every limit', 'Warnings at 80% and 100%', 'Requests for more, with the price shown'] },
    { icon: 'layout', title: 'Your other tools, inside the CRM',
        text: 'Put the page your team already uses where they already work: a tab on every contact, or a page in the main menu, shown to everyone or only to the people you choose.',
        points: ['Embed a web page that knows the contact or the person looking', 'Or write your own content with a rich text editor', 'Choose who sees it: roles, groups or named people'] },
    { icon: 'users', title: 'A team you can see and manage',
        text: 'Everyone has a profile with a photo, a job title and their details. Administrators see who signed in, who is online now and how much time they spend here.',
        points: ['Photos, job titles, phones and time zones', 'Last sign-in, online now and time spent', 'Decide who sees which tab, page and board'] },
    { icon: 'link', title: 'Plays well with what you already use',
        text: 'Bring your contacts across, send through the providers you trust and connect anything else with webhooks.',
        points: ['Keap contact sync', 'Your own Twilio account, SendGrid or any SMTP server', 'Import and export CSV any time'] },
];

export const comparison = {
    columns: ['Spreadsheets', 'Separate tools', 'OrvynCRM'],
    rows: [
        ['Your own record types and fields', ['Manual', 'Limited', 'Built in']],
        ['Follow-ups sent automatically', ['No', 'Needs another tool', 'Built in']],
        ['E-mail and SMS next to the contact', ['No', 'Needs syncing', 'Built in']],
        ['A history of what happened and when', ['Partial', 'Scattered', 'Every run recorded']],
        ['Each company\'s data kept separate', ['By hand', 'Varies', 'Always']],
    ] as [string, [string, string, string]][],
};

export type Change = { month: string; tag: string; title: string; text: string; href?: string };

/** What was added, newest first. Add an entry whenever a user-visible feature ships (shown on /changelog and in the home hero). */
export const changelog: Change[] = [
    { month: 'October 2026', tag: 'Boards', title: 'Workflow boards you can work from', text: 'A summary bar shows how your items spread across the columns, and search and filters cover every item, not only the cards on screen. Click a card to open a wide window with its details, contacts, files and recent activity, and email, text, move, edit or delete from there. Drag a card and it tilts as you carry it, the board scrolls by itself, and the card settles into place.', href: '/docs/data-objects' },
    { month: 'October 2026', tag: 'Customize', title: 'Your own tabs and pages', text: 'Add a tab to every contact, or a page to the main menu under Your pages. Embed a web page that knows who is looking, or write your own content in a rich text editor, with a description in your own words. Show it to everyone, or only to chosen roles, groups and people.', href: '/docs/data-objects' },
    { month: 'October 2026', tag: 'People', title: 'Photos, details and sign-in activity for your team', text: 'Every person has a photo, job title, phone, time zone and notes. Administrators see when each person last signed in, who is online now and how much time they spent, in each company and across the platform.', href: '/docs/team-and-admin' },
    { month: 'October 2026', tag: 'E-mail', title: 'A proper editor for every e-mail', text: 'Write e-mails, signatures and templates with a word-processor toolbar: headings, fonts, colours, lists, links, pictures, and the HTML itself. Merge fields now work for connected data too, and name the object they come from, so two objects with the same field name never clash.', href: '/docs/messaging' },
    { month: 'October 2026', tag: 'Customize', title: 'Data objects at a glance', text: 'The Data Objects page shows each object with its record count, what was added this week, its fields, relationships and boards. Fields, settings and import or export open in wide windows, and every field can be 25, 33, 50 or 100 percent wide on the form.', href: '/docs/data-objects' },
    { month: 'October 2026', tag: 'Contacts', title: 'Faces for your contacts', text: 'Every contact now has a picture: upload a photo, use their Gravatar when there is one, or see a clear male or female icon with the initials underneath. Pictures load only for the rows you see and are remembered, so long lists stay fast.', href: '/docs/contacts' },
    { month: 'October 2026', tag: 'Contacts', title: 'Notes and tasks that work together', text: 'Turn a note into a task in one click, link a task to the note it is about, and let completing a task write itself down on the contact. Notes and tasks can now carry documents, images and links.', href: '/docs/contacts' },
    { month: 'October 2026', tag: 'Contacts', title: 'Open a contact without losing your place', text: 'Click a name and the whole contact opens in a wide window over the list, so your search and page are still there when you close it. Tick contacts and e-mail or text them straight from the action bar.', href: '/docs/contacts' },
    { month: 'October 2026', tag: 'Dashboard', title: 'Widget results as a real table', text: 'Dashboard widgets show the first few matches; View all opens the full list as a table you can search, sort by any column and page through, with checkboxes to download, e-mail or text just the rows you pick.', href: '/docs/contacts' },
    { month: 'October 2026', tag: 'E-mail', title: 'Unsubscribe links and the progress of every send', text: 'Every automation and bulk e-mail now ends with an unsubscribe link, and mail programs show their own unsubscribe button. Each Email all and Text all is tracked as one send: how many people it found, how many went out, which failed and why, and how many opened.', href: '/docs/messaging' },
    { month: 'October 2026', tag: 'E-mail', title: 'Email all and Text all, everywhere', text: 'The same form as e-mailing one contact (templates, merge fields, formatting, attachments) on dashboard widgets and in Advanced search, with a Download CSV button, and merge fields in texts too.', href: '/docs/contacts' },
    { month: 'October 2026', tag: 'Automations', title: 'Timed follow-ups you can read', text: 'Wait minutes, hours or days before an e-mail or text goes out, and see the whole sequence as a timeline: immediately, after 2 days, after a week.', href: '/docs/automations' },
    { month: 'October 2026', tag: 'Security', title: 'Two-step verification, passkeys and single sign-on', text: 'Protect accounts with an authenticator app, sign in with a fingerprint, face or device PIN, or continue with Google, Microsoft, GitHub or your own provider. Platforms can require two-step verification for administrators or everybody.', href: '/docs/team-and-admin' },
    { month: 'October 2026', tag: 'Contacts', title: 'A contact list that keeps up', text: 'Sort by any column, move through numbered pages, and e-mail, text or edit a contact without leaving the list. Unsaved changes are now asked about before you leave a form.', href: '/docs/contacts' },
    { month: 'October 2026', tag: 'Getting started', title: 'Easier to start, easier to find things', text: 'The free trial needs no credit card. New workspaces get a getting-started checklist, and Admin settings have a menu of every setting with live details on each card.', href: '/docs/getting-started' },
    { month: 'October 2026', tag: 'Billing', title: 'Clearer plans and payments', text: 'Plan changes explain what will happen first, administrators are told when a payment fails, and companies that are invoiced have their invoices tracked and followed up.', href: '/docs/plans-and-billing' },
];

export type Feature = { icon: string; title: string; text: string; points: string[] };

export const features: Feature[] = [
    { icon: 'users', title: 'Contacts that tell the whole story',
        text: 'One record per person with everything around it: notes, tasks, messages, relationships and files, and a face to put to the name.',
        points: ['Open a contact in a wide window without losing your place in the list', 'Notes and tasks that link to each other and carry files, images and links', 'Pictures from an upload or Gravatar, and e-mail or text straight from the list', 'Advanced search, saved queries, duplicate merge, CSV import and export'] },
    { icon: 'boxes', title: 'Your own data, your own model',
        text: 'Define the record types your business really has (deals, properties, tours, subscriptions) and the fields they need.',
        points: ['Custom data objects and fields, each as wide on the form as you like', 'Shown as tabs on a contact, with history and attachments', 'Items in a table you can search, sort, filter and export', 'Import and export in a wide window, with a ready-made template'] },
    { icon: 'kanban', title: 'Workflow boards that are a pleasure to use',
        text: 'See any record type as columns by status. Drag a card to move it, open it to see everything and act on it, and find anything with search and filters.',
        points: ['Drag cards between columns; the board scrolls and the card settles in', 'Search and filters that cover every item, not just the visible ones', 'Open a card: details, contacts, files and activity, with Email, Text, Move, Edit and Delete', 'A colour overview of every board and a total per column'] },
    { icon: 'zap', title: 'Automations that work while you sleep',
        text: 'Pick a condition, choose the actions, and let it run. Every run is recorded, so you can see what happened and why.',
        points: ['E-mail, SMS, tags, tasks, field updates and webhooks', 'Timed follow-ups: wait minutes, hours or days, shown as a timeline', 'Runs on a schedule or when a record changes', 'Run history with the result of each action'] },
    { icon: 'mail', title: 'E-mail and SMS, built in',
        text: 'Write once and reach people where they read. Templates, tracking and delivery reports are included.',
        points: ['A rich text editor with headings, fonts, colours, lists, links and pictures', 'Merge fields, including connected data, and templates', 'Email all and Text all to any search, with the progress of every send', 'An unsubscribe link in every bulk e-mail; opens, clicks and bounces tracked'] },
    { icon: 'layout', title: 'Your own tabs and pages',
        text: 'Put the tools your team already uses inside OrvynCRM: a tab on every contact, or a page in the main menu.',
        points: ['Embed a web page that knows the contact or the person looking', 'Or write your own content in a rich text editor', 'Show it to everyone, or only to chosen roles, groups and people', 'A picture, a title and a description in your own words'] },
    { icon: 'form', title: 'Forms that feed your CRM',
        text: 'Build a form, put it on your website, and every answer lands as a contact with the right data.',
        points: ['Hosted or embedded forms', 'Fields mapped to your own data model', 'Webhooks for everything else'] },
    { icon: 'calendar', title: 'Plan the work',
        text: 'Tasks, a schedule calendar and dashboards keep a team pointed at what matters today.',
        points: ['My tasks you can open, read and complete in place', 'A calendar of the dates that matter', 'Dashboard widgets from your own queries, with a table of every result'] },
    { icon: 'shield', title: 'Control for administrators',
        text: 'Decide who sees and does what, see who is using the workspace, and keep it looking like your company.',
        points: ['Users, photos, job titles and permission templates', 'Last sign-in, online now and time spent for every person', 'Two-step verification, passkeys and single sign-on', 'Your logo (as tall as you want it), colours and time zone'] },
    { icon: 'plans', title: 'Plans that grow with you',
        text: 'Start with a free trial, pay by card or by invoice, and move up a plan whenever you need more room.',
        points: ['Five plans from a small team to unlimited users', 'Card payment on Stripe, invoices and receipts', 'Warnings at 80% and 100% of every limit'] },
    { icon: 'api', title: 'Built to be connected',
        text: 'A JSON API, signed webhooks and forms let your own systems talk to OrvynCRM, with the same permissions as the person using them.',
        points: ['Token sign-in and a full REST API', 'Webhooks signed with HMAC-SHA256', 'Documentation with examples to copy'] },
    { icon: 'link', title: 'Connects to what you use',
        text: 'Bring your contacts from Keap, send through your own Twilio and mail provider, and call out to anything with webhooks.',
        points: ['Keap contact sync', 'Your own Twilio account, or the platform\'s', 'SendGrid or any SMTP server'] },
];

export const steps = [
    { title: 'Model your business', text: 'Create the record types and fields you need. No code, no consultant.' },
    { title: 'Bring your people', text: 'Import contacts from a CSV or sync them from Keap, then invite your team.' },
    { title: 'Let it run', text: 'Switch on automations and messages, and watch the work happen on its own.' },
];

export type Person = {
    role: string; chip: string; line: string; backdrop: string;
    /** A real photo (stock portraits for now; swap in your own customers or team). Without one, a drawn person is shown. Put a licensed photo in /public/people and set its path here to show it instead (e.g. '/people/maria.jpg'). */
    photo?: string;
    photoAlt?: string;
    look: { skin?: string; hair?: string; style?: 'long' | 'short' | 'bun' | 'curly'; outfit?: string; shirt?: string; glasses?: boolean };
};

/** The people the product is for: what they get, in their own words of work (no invented quotes). */
export const people: Person[] = [
    { role: 'Sales lead', photo: '/people/sales.jpg', photoAlt: 'A smiling sales professional in a beige blazer', chip: 'Deal won', line: 'Knows which deals are warm and what to do next, without digging through spreadsheets.', backdrop: 'bg-gradient-to-br from-brand-100 to-gold-100',
        look: { skin: '#c68642', hair: '#1f1612', style: 'short', outfit: '#4a6f9a', glasses: true } },
    { role: 'Business owner', photo: '/people/owner.jpg', photoAlt: 'A smiling business owner with curly hair', chip: 'Follow-up sent', line: 'Sees the whole business in one place and trusts the follow-ups are going out on time.', backdrop: 'bg-gradient-to-br from-gold-100 to-gold-100',
        look: { skin: '#f5d0b5', hair: '#7c4a21', style: 'long', outfit: '#1d2433' } },
    { role: 'Customer support', photo: '/people/support.jpg', photoAlt: 'A smiling customer support agent wearing a headset', chip: 'Task done', line: 'Answers quickly with the full history of every person in front of them.', backdrop: 'bg-gradient-to-br from-stone-200 to-gold-100',
        look: { skin: '#8d5524', hair: '#140d0a', style: 'curly', outfit: '#a69a7f' } },
    { role: 'Marketing manager', photo: '/people/marketing.jpg', photoAlt: 'A smiling marketing manager holding a tablet', chip: 'Campaign sent', line: 'Builds the form, the e-mail and the automation, and watches the leads arrive.', backdrop: 'bg-gradient-to-br from-brand-100 to-brand-100',
        look: { skin: '#ffdbac', hair: '#2b1b12', style: 'bun', outfit: '#a9803a', glasses: true } },
];

/** `users: null` means unlimited. The numbers drive the capacity bars and the plan finder on the pricing page. */
export type Plan = { name: string; blurb: string; price: string; users: number | null; texts: number; numbers: number; cta: string; href?: string };

/**
 * The five plans of the app (the platform can change their names, numbers and prices, so the figures here are the defaults and the
 * page says so). Prices are not published: they read "Talk to us". Put real numbers in `price` when they are decided.
 */
export const plans: Plan[] = [
    { name: 'Tier 1', blurb: 'A small team getting organised.', price: 'Talk to us', users: 5, texts: 100, numbers: 1, cta: 'Start free trial', href: 'signup' },
    { name: 'Tier 2', blurb: 'A growing team with more follow-ups to send.', price: 'Talk to us', users: 15, texts: 500, numbers: 3, cta: 'Start free trial', href: 'signup' },
    { name: 'Tier 3', blurb: 'Several teams working in one place.', price: 'Talk to us', users: 50, texts: 1000, numbers: 5, cta: 'Request a demo' },
    { name: 'Tier 4', blurb: 'A large organisation with busy campaigns.', price: 'Talk to us', users: 100, texts: 3000, numbers: 10, cta: 'Request a demo' },
    { name: 'Tier 5', blurb: 'No limit on people, and the most texting.', price: 'Talk to us', users: null, texts: 5000, numbers: 20, cta: 'Contact us' },
];

export const fmtUsers = (n: number | null) => (n === null ? 'Unlimited' : n.toLocaleString('en-US'));
export const fmtNumber = (n: number) => n.toLocaleString('en-US');

/** What every plan includes, whatever its size. */
export const everyPlan = [
    'Contacts with notes, tasks, history and attachments',
    'Your own data objects, fields, boards, tabs and pages',
    'Automations with e-mail, text, tags, tasks, field updates and webhooks',
    'E-mail with templates and tracking, and text through Twilio',
    'Forms for your website, webhooks and CSV import and export',
    'The API and the documentation',
    'Pay by card on Stripe, or be invoiced',
    'A free trial, then clear meters for every limit',
];

export const planLimits = {
    columns: plans.map((p) => p.name),
    rows: [
        ['Active users', plans.map((p) => fmtUsers(p.users))],
        ['Texts per month', plans.map((p) => fmtNumber(p.texts))],
        ['Texting numbers', plans.map((p) => fmtNumber(p.numbers))],
        ['Contacts and data objects', plans.map(() => 'Included')],
        ['Automations, e-mail, forms, API', plans.map(() => 'Included')],
        ['Ask for more of anything', plans.map(() => 'Yes')],
    ] as [string, string[]][],
};

export const faq = [
    { q: 'Is there a free trial?', a: 'Yes. A workspace you create yourself starts with a free trial on the first plan. The app shows how many days are left and reminds you 14, 7 and 1 day before it ends. No credit card is needed to start or during the trial. Billing details are only asked for when the trial ends and you want to keep going; you can also add them earlier if you prefer.' },
    { q: 'Can I add more users, data objects, automations or texts later?', a: 'Yes. Administrators see how much of each limit is used and can ask for more at any time, with the price shown. A bigger plan raises the limits at once. Extra texts can be given for every month, or for one month only.' },
    { q: 'How do I pay?', a: 'By card on Stripe\'s own secure page, so OrvynCRM never sees your card number, or by invoice if your platform invoices you. You can change the card, download invoices and cancel from the billing page.' },
    { q: 'What happens when I reach a limit?', a: 'You are told at 80% and again at 100%. At the user limit you cannot add more people until you free a seat or move up. At the text limit, further texts are not sent and show as failed with an explanation, until the next month or a bigger plan.' },
    { q: 'Can I put my own tools or pages inside OrvynCRM?', a: 'Yes. Custom tabs add a tab to every contact, or a page to the main menu. A tab can embed a web page (it can carry the contact or the person looking in its address) or show content you write in a rich text editor. You choose whether everyone sees it or only certain roles, permission groups or people.' },
    { q: 'Can I see who is using the workspace?', a: 'Administrators see every person with their photo and job title, when they last signed in, whether they are online now and how much time they have spent. The platform sees the same across companies.' },
    { q: 'Is my data kept apart from other companies?', a: 'Yes. Every company has its own database, its own people and its own data model.' },
    { q: 'Can I move my contacts over from another system?', a: 'Yes. Import them from a CSV, or connect your Keap account and sync them across.' },
    { q: 'Do I need my own e-mail or SMS provider?', a: 'No. E-mail is sent through the platform\'s server and text messages through its Twilio account. You can use your own Twilio account if you prefer your own numbers.' },
    { q: 'Can I connect my own systems?', a: 'Yes. There is a JSON API with the same permissions as the person using it, signed webhooks that an automation can call, and forms that feed your contacts. See the documentation.' },
    { q: 'What happens to an automation if e-mail or SMS is not set up?', a: 'You are told while you build it: the editor warns you that those actions will not run until the sending channel is set up.' },
];
