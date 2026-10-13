import { AlarmClock, Boxes, MessageSquare, CalendarDays, CheckSquare, Columns3, ConciergeBell, Database, FileText, FormInput, Kanban, LayoutDashboard, Mail, MailCheck, Map as MapIcon, Palette, Phone, PlugZap, Rows3, Search, Settings2, Tag, Users, Workflow, Zap } from 'lucide-vue-next';

/** Main sidebar entries (the Admin entry is added for administrators by the layout). */
export const MAIN_NAV = [
    { href: '/', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/contacts', label: 'Contacts', icon: Users },
    { href: '/objects', label: 'Data Objects', icon: Boxes },
    { href: '/boards', label: 'Workflow Boards', icon: Kanban },
    { href: '/tasks', label: 'My Tasks', icon: CheckSquare },
    { href: '/schedules', label: 'Schedules', icon: CalendarDays },
];

/** Admin settings: one source for the hub cards, the sub-navigation and the command palette. */
export const ADMIN_GROUPS = [
    { title: 'General', items: [
        { href: '/admin/app-details', icon: Settings2, title: 'App details', text: 'Timezone, generated email format and API access.' },
        { href: '/admin/look-and-feel', icon: Palette, title: 'Look & feel', text: 'Brand logo and theme colour tailored to your company.' },
        { href: '/admin/users', icon: Users, title: 'User management', text: 'Manage user accounts and permission templates.' },
        { href: '/admin/phone', icon: Phone, title: 'Phone call', text: 'Configure users with caller IDs.' },
        { href: '/admin/platform-messages', icon: MessageSquare, title: 'Message the platform', text: 'Write directly to the platform administrators and see what you sent.' },
    ] },
    { title: 'Data', items: [
        { href: '/objects', icon: Boxes, title: 'Object builder', text: 'Configure data objects, their fields and relationship rules.' },
        { href: '/admin/contact-relationships', icon: PlugZap, title: 'Contact relationships', text: 'Manage direct and indirect contact-to-contact relationships.' },
        { href: '/admin/queries', icon: Search, title: 'Query builder', text: 'Build queries to show specific data in dashboard widgets.' },
        { href: '/admin/data-tools', icon: Database, title: 'Data import & export', text: 'Import new data or export existing data.' },
        { href: '/admin/custom-tabs', icon: Rows3, title: 'Custom tabs', text: 'Tabs with customised content in the contact page sidebar.' },
    ] },
    { title: 'Automation & communication', items: [
        { href: '/admin/twilio', icon: MessageSquare, title: 'Twilio', text: 'Use your own Twilio account for text messages and phone numbers.' },
        { href: '/admin/keap', icon: PlugZap, title: 'Keap', text: 'Connect your Keap account so the platform can sync your contacts.' },
        { href: '/automations', icon: Zap, title: 'Automation', text: 'Configure automated processes by choosing conditions and actions.' },
        { href: '/admin/webhooks', icon: Workflow, title: 'Webhooks', text: 'Outbound HTTPS endpoints your automations can call.' },
        { href: '/admin/email-templates', icon: Mail, title: 'Email templates', text: 'Reusable email templates, grouped for the composers.' },
        { href: '/admin/forms', icon: FormInput, title: 'Form builder', text: 'Build forms to embed in your website with custom fields.' },
        { href: '/admin/blast-progress', icon: ConciergeBell, title: 'Blast progress', text: 'Track progress of all user emails and SMS in the queue.' },
        { href: '/admin/support-emails', icon: MailCheck, title: 'Internal support emails', text: 'Add or remove internal support emails.' },
        { href: '/admin/email-domains', icon: Tag, title: 'Email sync domains', text: 'Exclude domains from email sync.' },
    ] },
    { title: 'Planning', items: [
        { href: '/admin/workflow-boards', icon: Columns3, title: 'Workflow boards', text: 'Boards for a clear overview of state across data objects.' },
        { href: '/admin/scheduler', icon: AlarmClock, title: 'Scheduler', text: 'Manage which data object dates appear on schedules.' },
        { href: '/admin/price-lists', icon: FileText, title: 'Price list generator', text: 'Build custom price lists you can share.' },
        { href: '/admin/tours', icon: MapIcon, title: 'Tour manager', text: 'Schedule and manage tours.' },
    ] },
];
export const ADMIN_ITEMS = ADMIN_GROUPS.flatMap((g) => g.items.map((i) => ({ ...i, group: g.title })));
