/**
 * Everything the pages say, in one place. Change the words here; the pages only lay them out.
 * The facts about the product come from the OrvynCRM README: keep them true.
 */

export const site = {
    name: 'OrvynCRM',
    tagline: 'Build. Connect. Automate.',
    description: 'OrvynCRM is a CRM that adapts to your business: your own record types, automations that run by themselves, and e-mail and SMS built in.',
    // Where the product lives. Set PUBLIC_APP_URL in .env.
    appUrl: (import.meta.env.PUBLIC_APP_URL || 'http://localhost:8000').replace(/\/$/, ''),
    // Where the demo form posts. Empty: the form falls back to an e-mail to contactEmail.
    demoFormUrl: import.meta.env.PUBLIC_DEMO_FORM_URL || '',
    // Where "Create an account" leads: the CRM's own sign-up page (open only while the platform has switched sign-ups on).
    get signupUrl() { return `${this.appUrl}/signup`; },
    contactEmail: import.meta.env.PUBLIC_CONTACT_EMAIL || 'hello@example.com',
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
];

/** What the product connects to. Names are shown as plain text, not as partner logos. */
export const integrations = [
    { name: 'Keap', kind: 'Contact sync', text: 'Pull your contacts from Keap into OrvynCRM on a schedule, using your own key.', icon: 'users' },
    { name: 'Twilio', kind: 'Text messages and calls', text: 'Send and track SMS through the shared account, or connect your own Twilio account and numbers.', icon: 'mail' },
    { name: 'SendGrid', kind: 'E-mail delivery', text: 'Send through SendGrid and get bounces, spam reports, unsubscribes and clicks back.', icon: 'mail' },
    { name: 'Any SMTP server', kind: 'E-mail delivery', text: 'Use the mail server you already have. Set it once; every company can send through it.', icon: 'mail' },
    { name: 'Webhooks', kind: 'Anything else', text: 'Let an automation call another system over HTTPS, and receive reports from your providers.', icon: 'link' },
    { name: 'CSV', kind: 'Import and export', text: 'Bring your contacts in from a spreadsheet and take your data out again whenever you like.', icon: 'form' },
];

/** Plain statements about how the product handles data. Each one is true of the product today. */
export const securityPoints = [
    { icon: 'boxes', title: 'A database of its own for every company', text: 'Records are not stored side by side with another company\'s. Each workspace has its own people, data model and database.' },
    { icon: 'shield', title: 'Secrets stay encrypted', text: 'Integration credentials, API tokens and mail passwords are encrypted when stored and are never shown again after you save them.' },
    { icon: 'users', title: 'Permissions that match your team', text: 'Permission templates decide who can see and do what. Administrators control who is in the workspace.' },
    { icon: 'calendar', title: 'A record of what happened', text: 'Records keep their history, and every automation run and message delivery is written down, so you can see what happened and when.' },
    { icon: 'mail', title: 'Safe sending', text: 'Bounces, spam reports and unsubscribes are recorded and respected, and sending is rate limited to protect your reputation.' },
    { icon: 'link', title: 'Your data goes where you say', text: 'Nothing leaves the workspace except through the integrations and webhooks you switch on. Export your data at any time.' },
];


export type Reason = { icon: string; title: string; text: string; points: string[] };

/** "Why OrvynCRM": each reason is something the product really does (see the README). No made-up numbers or claims about other products. */
export const reasons: Reason[] = [
    { icon: 'boxes', title: 'It fits your business, not the other way round',
        text: 'Most CRMs make you describe your business in their words. OrvynCRM lets you create the record types and fields your business actually has, and shows them on every contact.',
        points: ['Create deals, properties, subscriptions, tours or anything else', 'Your fields, your tabs, your workflow boards', 'Change it later without a consultant or a developer'] },
    { icon: 'zap', title: 'Follow-ups that happen, and a record that proves it',
        text: 'Automations send the e-mail, text the contact, create the task or call another system. Every run is written down, so you never wonder whether something went out.',
        points: ['Conditions and actions per record type', 'Run history with the result of each step', 'Told while you build if e-mail or SMS is not set up, so nothing silently fails'] },
    { icon: 'mail', title: 'Talk to people where they read',
        text: 'E-mail and SMS are part of the CRM, not a separate tool to keep in sync. Templates, tracking and delivery reports sit next to the contact they belong to.',
        points: ['One composer for e-mail and text', 'Opens, clicks, bounces and unsubscribes tracked', 'Use the shared account, or bring your own Twilio'] },
    { icon: 'shield', title: 'Your data stays yours, and apart',
        text: 'Every company has its own database, its own people and its own data model. One company can never see another\'s records.',
        points: ['A separate database for every company', 'Permission templates decide who sees and does what', 'Your own logo, colours and time zone'] },
    { icon: 'calendar', title: 'Clear limits, no surprises',
        text: 'You always see how much you use: users, record types and automations. Need more? Ask inside the app and see the price before you confirm.',
        points: ['"3 of 5" meters for every limit', 'Requests for more, with the price shown', 'Administrators and the platform team stay in the loop'] },
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

export type Feature = { icon: string; title: string; text: string; points: string[] };

export const features: Feature[] = [
    { icon: 'users', title: 'Contacts that tell the whole story',
        text: 'One record per person with everything around it: notes, tasks, messages, relationships and files.',
        points: ['Overview, notes and tasks, communications, relationships and attachments', 'Advanced search and a query builder you can save', 'Merge duplicates, import and export CSV'] },
    { icon: 'boxes', title: 'Your own data, your own model',
        text: 'Define the record types your business really has (deals, properties, tours, subscriptions) and the fields they need.',
        points: ['Custom data objects and fields', 'Shown as tabs on a contact, with history and attachments', 'Move records across workflow boards'] },
    { icon: 'zap', title: 'Automations that work while you sleep',
        text: 'Pick a condition, choose the actions, and let it run. Every run is recorded, so you can see what happened and why.',
        points: ['E-mail, SMS, tags, tasks, field updates and webhooks', 'Runs on a schedule or when a record changes', 'Run history with the result of each action'] },
    { icon: 'mail', title: 'E-mail and SMS, built in',
        text: 'Write once and reach people where they read. Templates, tracking and delivery reports are included.',
        points: ['Composer with reusable templates', 'Opens, clicks, bounces and unsubscribes tracked', 'Text messages through Twilio, on your account or ours'] },
    { icon: 'form', title: 'Forms that feed your CRM',
        text: 'Build a form, put it on your website, and every answer lands as a contact with the right data.',
        points: ['Hosted or embedded forms', 'Fields mapped to your own data model', 'Webhooks for everything else'] },
    { icon: 'calendar', title: 'Plan the work',
        text: 'Tasks, a schedule calendar and dashboards keep a team pointed at what matters today.',
        points: ['My tasks and overdue digests', 'A calendar of the dates that matter', 'Dashboard widgets from your own queries'] },
    { icon: 'shield', title: 'Control for administrators',
        text: 'Decide who sees and does what, and keep the workspace looking like your company.',
        points: ['Users and permission templates', 'Your logo, colours and time zone', 'Limits with clear meters, and a way to ask for more'] },
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

export type Plan = { name: string; blurb: string; price: string; unit: string; featured?: boolean; includes: string[]; cta: string; href?: string };

/** Prices are not decided yet: they read "Talk to us". Put real numbers in `price` and `unit` when they are. */
export const plans: Plan[] = [
    { name: 'Team', blurb: 'For a small team getting organised.', price: 'Talk to us', unit: '', cta: 'Create a workspace', href: 'signup',
        includes: ['5 active users', '10 data objects', '5 automations per data object', 'E-mail and SMS sending', 'Forms and webhooks'] },
    { name: 'Business', blurb: 'For growing companies with more to automate.', price: 'Talk to us', unit: '', featured: true, cta: 'Request a demo',
        includes: ['More users, data objects and automations', 'Your own Twilio account for SMS', 'Keap contact sync', 'Priority help from the platform team', 'Everything in Team'] },
    { name: 'Custom', blurb: 'For larger or unusual needs.', price: 'Let\'s talk', unit: '', cta: 'Contact us',
        includes: ['Limits set for your company', 'Dedicated help with setup and import', 'Tailored onboarding', 'Everything in Business'] },
];

export const planLimits = {
    columns: ['Team', 'Business', 'Custom'],
    rows: [
        ['Active users', ['5', 'More', 'Set for you']],
        ['Data objects (your record types)', ['10', 'More', 'Set for you']],
        ['Automations per data object', ['5', 'More', 'Set for you']],
        ['E-mail and SMS sending', ['Included', 'Included', 'Included']],
        ['Forms, webhooks and CSV import', ['Included', 'Included', 'Included']],
        ['Your own Twilio account', ['Optional', 'Included', 'Included']],
        ['Keap contact sync', ['', 'Included', 'Included']],
        ['Help with setup and import', ['', 'Priority', 'Dedicated']],
    ] as [string, [string, string, string]][],
};

export const faq = [
    { q: 'Can I add more users, data objects or automations later?', a: 'Yes. Administrators see how much of each limit is used, and can ask for more at any time with the price shown.' },
    { q: 'Is my data kept apart from other companies?', a: 'Yes. Every company has its own database, its own people and its own data model.' },
    { q: 'Can I move my contacts over from another system?', a: 'Yes. Import them from a CSV, or connect your Keap account and sync them across.' },
    { q: 'Do I need my own e-mail or SMS provider?', a: 'No. E-mail is sent through the platform\'s server and text messages through its Twilio account. You can use your own Twilio account if you prefer.' },
    { q: 'What happens to an automation if e-mail or SMS is not set up?', a: 'You are told while you build it: the editor warns you that those actions will not run until the sending channel is set up.' },
];
