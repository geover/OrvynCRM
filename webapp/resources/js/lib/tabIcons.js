import { BarChart3, BookOpen, Briefcase, CalendarDays, FileText, Globe, GraduationCap, Inbox, LayoutDashboard, LifeBuoy, Link2, Map as MapIcon, Megaphone, ShoppingCart, Star, Wrench } from 'lucide-vue-next';

// The pictures an administrator can give a custom tab (the keys are what is saved; the server accepts only these).
export const TAB_ICONS = {
    globe: [Globe, 'Web'], 'file-text': [FileText, 'Document'], 'book-open': [BookOpen, 'Guide'], 'layout-dashboard': [LayoutDashboard, 'Dashboard'],
    'bar-chart': [BarChart3, 'Report'], calendar: [CalendarDays, 'Calendar'], link: [Link2, 'Link'], briefcase: [Briefcase, 'Work'],
    'graduation-cap': [GraduationCap, 'Training'], 'life-buoy': [LifeBuoy, 'Support'], 'shopping-cart': [ShoppingCart, 'Shop'], wrench: [Wrench, 'Tool'],
    star: [Star, 'Favourite'], megaphone: [Megaphone, 'News'], map: [MapIcon, 'Map'], inbox: [Inbox, 'Inbox'],
};
export const tabIcon = (key) => (TAB_ICONS[key] ?? TAB_ICONS.globe)[0];

/** The content of a tab as a page of its own (it is shown in a protected frame, which has no styling of its own): readable type, headings, lists, quotes, pictures and links. */
export const frameDocument = (html) => `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base target="_blank"><style>
body{margin:0;padding:1.25rem 1.5rem;font:15px/1.6 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#1e293b;background:#fff}
h1{font-size:1.75rem;margin:.6em 0 .4em}h2{font-size:1.4rem;margin:.6em 0 .4em}h3{font-size:1.15rem;margin:.6em 0 .4em}p{margin:.5em 0}
ul{list-style:disc;padding-left:1.6rem}ol{list-style:decimal;padding-left:1.6rem}blockquote{border-left:3px solid #cbd5e1;margin:.8em 0;padding-left:1rem;color:#475569}
pre{background:#f1f5f9;border-radius:6px;padding:.7rem;white-space:pre-wrap}a{color:#2563eb}hr{border:0;border-top:1px solid #cbd5e1;margin:1rem 0}img{max-width:100%;height:auto}
table{border-collapse:collapse}td,th{border:1px solid #e2e8f0;padding:.35rem .6rem}</style>${html}`;

/** The address of an embedded page with the person (and the contact, on a contact's tab) filled in. */
export function fillTokens(url, { user, contact } = {}) {
    const enc = (v) => encodeURIComponent(v ?? '');
    return String(url)
        .replaceAll('{{contact.id}}', enc(contact?.id)).replaceAll('{{contact.email}}', enc(contact?.email))
        .replaceAll('{{user.id}}', enc(user?.id)).replaceAll('{{user.email}}', enc(user?.email)).replaceAll('{{user.name}}', enc(user?.name));
}
