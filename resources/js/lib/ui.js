// Tiny global UI state: toasts and confirm dialogs (hosted by Components/Hosts.vue in the layout).
import { reactive } from 'vue';

export const toasts = reactive([]);
let tid = 0;

export function toast(message, type = 'success', ms = 4200) {
    const t = { id: ++tid, message, type, ms };
    toasts.push(t);
    setTimeout(() => {
        const i = toasts.findIndex((x) => x.id === t.id);
        if (i >= 0) toasts.splice(i, 1);
    }, ms);
}
export const toastError = (e) => toast(e?.message || 'Something went wrong', 'error', 6000);

export const confirmState = reactive({ open: false, title: '', message: '', confirmLabel: 'Confirm', danger: false, resolve: null });

export function confirmDialog({ title = 'Are you sure?', message = '', confirmLabel = 'Confirm', danger = false } = {}) {
    return new Promise((resolve) => Object.assign(confirmState, { open: true, title, message, confirmLabel, danger, resolve }));
}
export function answerConfirm(value) {
    confirmState.open = false;
    confirmState.resolve?.(value);
}

/** Run an async action with toast feedback. Returns the result or undefined on failure. */
export async function run(fn, successMessage) {
    try {
        const r = await fn();
        if (successMessage) toast(successMessage);
        return r ?? true;
    } catch (e) {
        toastError(e);
        return undefined;
    }
}

// Organisation timezone (Admin -> App details). Set once by the layout; undefined = browser timezone.
let displayTimezone;
export const setDisplayTimezone = (tz) => { displayTimezone = tz || undefined; };
export const getDisplayTimezone = () => displayTimezone;

export const fmtDate = (v, withTime = false) => {
    if (!v) return '';
    const d = typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v) ? new Date(v + 'T12:00:00') : new Date(v);   // plain dates carry no time: never shift them
    if (isNaN(d)) return v;
    const tz = typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v) ? undefined : displayTimezone;
    try {
        return withTime ? d.toLocaleString(undefined, { timeZone: tz }) : d.toLocaleDateString(undefined, { timeZone: tz });
    } catch {
        return withTime ? d.toLocaleString() : d.toLocaleDateString();   // unknown timezone id: fall back safely
    }
};

/** "just now", "5 min ago", "3 h ago", "2 d ago"; older than a week falls back to the date. */
export const fmtRelative = (v) => {
    if (!v) return '';
    const secs = Math.round((Date.now() - new Date(v).getTime()) / 1000);
    if (isNaN(secs)) return String(v);
    if (secs < 45) return 'just now';
    if (secs < 3600) return `${Math.round(secs / 60)} min ago`;
    if (secs < 86400) return `${Math.round(secs / 3600)} h ago`;
    if (secs < 7 * 86400) return `${Math.round(secs / 86400)} d ago`;
    return fmtDate(v);
};

/** "Today", "Tomorrow", "Yesterday", otherwise the date: for due dates. */
export const fmtDue = (v) => {
    if (!v) return '';
    const d = new Date(v);
    if (isNaN(d)) return String(v);
    const a = new Date(); a.setHours(0, 0, 0, 0);
    const b = new Date(d); b.setHours(0, 0, 0, 0);
    const days = Math.round((b - a) / 86400000);
    return days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : days === -1 ? 'Yesterday' : fmtDate(v);
};
