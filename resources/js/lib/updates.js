import { ref } from 'vue';
import { CheckCircle2, Megaphone, Newspaper, Sparkles, Wrench } from 'lucide-vue-next';

/** How each kind of announcement looks: label, colours (readable in light and dark) and icon. */
export const CATEGORY_META = {
    update: { label: 'Update', icon: Sparkles, badge: 'bg-indigo-100 text-indigo-700', dot: 'bg-indigo-500' },
    news: { label: 'News', icon: Newspaper, badge: 'bg-cyan-100 text-cyan-700', dot: 'bg-cyan-500' },
    improvement: { label: 'Improvement', icon: CheckCircle2, badge: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-500' },
    fix: { label: 'Fix', icon: Wrench, badge: 'bg-amber-100 text-amber-800', dot: 'bg-amber-500' },
    maintenance: { label: 'Maintenance', icon: Megaphone, badge: 'bg-rose-100 text-rose-700', dot: 'bg-rose-500' },
};
export const categoryMeta = (key) => CATEGORY_META[key] ?? CATEGORY_META.update;

// What this browser has already read: the highest announcement id seen (a per-viewer convenience, never needed to work).
const KEY = 'orvyn:updates-seen';
const read = () => { try { return Number(localStorage.getItem(KEY)) || 0; } catch { return 0; } };
const seen = ref(read());

/** How many of these announcement ids are newer than the last time the person opened "What's new". */
export const unreadUpdates = (ids) => ids.filter((id) => id > seen.value).length;

export function markUpdatesSeen(ids) {
    const top = Math.max(0, ...ids);
    if (top <= seen.value) return;
    seen.value = top;
    try { localStorage.setItem(KEY, String(top)); } catch { /* private window: the badge just comes back next visit */ }
}
