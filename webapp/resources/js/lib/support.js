import { Bug, CreditCard, HelpCircle, Lightbulb, MoreHorizontal } from 'lucide-vue-next';
import { reactive } from 'vue';

// Support tickets: a problem a company's administrators report and the platform works on. Shared by the company's pages and the platform's.

/** "Report a problem" can be opened from any page (the account menu); the host mounted in the layout shows the form. */
export const report = reactive({ open: false });
export const openReport = () => { report.open = true; };
export const closeReport = () => { report.open = false; };

/** The company's words for a status, then the platform's: whose turn it is. [label, badge classes, hint] */
export const COMPANY_STATUS = {
    open: ['With support', '!bg-sky-50 !text-sky-700', 'The platform will answer soon.'],
    waiting: ['Waiting for you', '!bg-amber-50 !text-amber-700', 'The platform replied and is waiting for your answer.'],
    resolved: ['Resolved', '!bg-emerald-50 !text-emerald-700', 'The platform thinks this is fixed. Reply if it is not.'],
    closed: ['Closed', '!bg-slate-100 !text-slate-600', 'This ticket is closed. You can reopen it.'],
};
export const PLATFORM_STATUS = {
    open: ['Open', '!bg-sky-50 !text-sky-700', 'The company is waiting for us.'],
    waiting: ['Waiting for the company', '!bg-amber-50 !text-amber-700', 'We replied; the company owes an answer.'],
    resolved: ['Resolved', '!bg-emerald-50 !text-emerald-700', 'Closes by itself if nobody writes.'],
    closed: ['Closed', '!bg-slate-100 !text-slate-600', ''],
};
export const PRIORITY = { low: ['Low', 'text-slate-500', '#94a3b8'], normal: ['Normal', 'text-slate-600', '#cbd5e1'], high: ['High', 'text-orange-600', '#f97316'], urgent: ['Urgent', 'text-red-600', '#ef4444'] };
/** A look for each kind of ticket: an icon and the gradient behind it. */
export const CATEGORY_LOOK = { bug: [Bug, 'bg-rose-100 text-rose-700'], question: [HelpCircle, 'bg-sky-100 text-sky-700'], request: [Lightbulb, 'bg-amber-100 text-amber-700'], billing: [CreditCard, 'bg-emerald-100 text-emerald-700'], other: [MoreHorizontal, 'bg-slate-100 text-slate-500'] };
export const CATEGORY = { bug: 'Something is not working', question: 'A question', request: 'A request', billing: 'Billing', other: 'Something else' };

/** "Chrome 120 on Windows": short enough to read, enough to reproduce a problem. */
export function browserName(agent = navigator.userAgent) {
    const b = /Edg\/(\d+)/.exec(agent) ? ['Edge', /Edg\/(\d+)/.exec(agent)[1]] : /OPR\/(\d+)/.exec(agent) ? ['Opera', /OPR\/(\d+)/.exec(agent)[1]] : /Firefox\/(\d+)/.exec(agent) ? ['Firefox', /Firefox\/(\d+)/.exec(agent)[1]] : /Chrome\/(\d+)/.exec(agent) ? ['Chrome', /Chrome\/(\d+)/.exec(agent)[1]] : /Version\/(\d+).*Safari/.exec(agent) ? ['Safari', /Version\/(\d+)/.exec(agent)[1]] : ['Browser', ''];
    const os = /Windows/.test(agent) ? 'Windows' : /Android/.test(agent) ? 'Android' : /iPhone|iPad/.test(agent) ? 'iOS' : /Mac OS X/.test(agent) ? 'macOS' : /Linux/.test(agent) ? 'Linux' : '';
    return `${b[0]}${b[1] ? ` ${b[1]}` : ''}${os ? ` on ${os}` : ''}`;
}

/** What helps the platform understand a report, taken from the page the person is on. */
export const reportContext = () => ({ page: `${location.origin}${location.pathname}${location.search}`.slice(0, 380), browser: browserName(), screen: `${window.innerWidth}x${window.innerHeight}`, language: navigator.language });
