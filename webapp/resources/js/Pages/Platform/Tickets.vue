<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCheck, CheckCircle2, Clock, ExternalLink, Hourglass, Inbox, KanbanSquare, List, RotateCcw, Search, Settings2, UserX } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Avatar from '@/Components/support/Avatar.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import TicketComposer from '@/Components/support/TicketComposer.vue';
import TicketThread from '@/Components/support/TicketThread.vue';
import { CATEGORY_LOOK, PLATFORM_STATUS, PRIORITY } from '@/lib/support';
import { liveCounts } from '@/lib/live';
import { fmtDate, fmtRelative, toast, toastError } from '@/lib/ui';

// Platform > Tickets: every company's tickets. Reply, leave a private note, and set the status, priority and owner. The company sees its replies, never the notes.
const props = defineProps({ tickets: Array, summary: Object, admins: Array, me: Number, settings: Object, categories: Object, priorities: Array, focus: String });

const rows = ref(props.tickets);
watch(() => props.tickets, (v) => { rows.value = v; });
const view = ref((() => { try { return localStorage.getItem('tickets.view') || 'board'; } catch { return 'board'; } })());
const setView = (v) => { view.value = v; try { localStorage.setItem('tickets.view', v); } catch { /* optional */ } };
const filter = ref('active');
const owner = ref('all');                       // all | mine | none
const priority = ref('');
const q = ref('');
const detail = ref(null);
const busy = ref(false);
const settingsOpen = ref(false);
const form = ref({ ...props.settings });

const FILTERS = [['active', 'Needs us'], ['waiting', 'Waiting for the company'], ['resolved', 'Resolved'], ['closed', 'Closed'], ['all', 'All']];
const matches = (t, k) => ({ all: true, active: t.status === 'open', waiting: t.status === 'waiting', resolved: t.status === 'resolved', closed: t.status === 'closed' })[k];
const count = (k) => rows.value.filter((t) => matches(t, k)).length;
const visible = (t) => {
    const term = q.value.trim().toLowerCase();
    if (term && !`${t.reference} ${t.subject} ${t.company}`.toLowerCase().includes(term)) return false;
    if (owner.value === 'mine' && t.assignee_id !== props.me) return false;
    if (owner.value === 'none' && t.assignee_id) return false;
    return !priority.value || t.priority === priority.value;
};
// the board: one column per status, most urgent first, then the longest waiting
const COLUMNS = [['open', 'Needs us', '#ef4444'], ['waiting', 'Waiting for the company', '#f59e0b'], ['resolved', 'Resolved', '#10b981'], ['closed', 'Closed', '#94a3b8']];
const RANK = { urgent: 0, high: 1, normal: 2, low: 3 };
const CLOSED_CAP = 15;
const column = (k) => rows.value.filter((t) => t.status === k && visible(t)).sort((a, b) => RANK[a.priority] - RANK[b.priority] || String(a.last_activity_at).localeCompare(String(b.last_activity_at)));
const cards = computed(() => Object.fromEntries(COLUMNS.map(([k]) => [k, column(k)])));
const dragging = ref(null);                   // { ticket, from }
const over = ref(null);                       // the column the card is over
const landed = ref(null);                     // the ticket that was just dropped: it settles into its place
const scroller = ref(null);                   // the row of columns, which scrolls sideways

/** The columns fill the window from where they start to its bottom edge, so their sideways scroll bar is always in view and each column scrolls inside itself. */
function fit() {
    if (!scroller.value) return;
    const top = scroller.value.getBoundingClientRect().top + window.scrollY;
    scroller.value.style.height = `${Math.max(384, window.innerHeight - top - 24)}px`;
}
const refit = () => nextTick(fit);
onMounted(() => { window.addEventListener('resize', fit); refit(); });
onBeforeUnmount(() => window.removeEventListener('resize', fit));
watch([view, rows, () => props.summary], refit, { flush: 'post' });

async function move(t, to) {
    if (!t || t.status === to) return;
    const was = t.status;
    t.status = to;                                                                // the card moves at once, and goes back if the server refuses
    landed.value = t.id; setTimeout(() => { if (landed.value === t.id) landed.value = null; }, 700);
    try { await call('PATCH', `/platform/tickets/${t.id}`, { status: to }); toast(to === 'resolved' ? `${t.reference} resolved. The company has been told.` : `${t.reference} moved to ${COLUMNS.find(([k]) => k === to)[1]}`); reload(); } catch (e) { t.status = was; toastError(e); }
}
function drop(status) {
    over.value = null;
    const d = dragging.value; dragging.value = null;
    if (d) move(d.ticket, status);
}

// The browser's own drag picture cannot be tilted reliably, so it is hidden and a copy of the card, tilted and lifted, follows the pointer instead.
let carrying = null;
let ghostEl = null;
function start(e, ticket) {
    dragging.value = { ticket, from: ticket.status };
    e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(ticket.id));
    stopCarrying();
    const el = e.currentTarget;
    const box = el.getBoundingClientRect();
    const dx = e.clientX - box.left; const dy = e.clientY - box.top;
    const blank = new Image(); blank.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    e.dataTransfer.setDragImage(blank, 0, 0);
    const ghost = el.cloneNode(true);
    ghost.removeAttribute('data-testid'); ghost.removeAttribute('tabindex'); ghost.classList.remove('opacity-40', 'msg-row');
    Object.assign(ghost.style, { position: 'fixed', zIndex: '60', width: `${box.width}px`, margin: '0', opacity: '1', pointerEvents: 'none', cursor: 'grabbing', transformOrigin: '50% 0', boxShadow: '0 16px 32px rgba(15, 23, 42, 0.3)', animation: 'none' });
    const place = (x, y) => { ghost.style.left = `${x - dx}px`; ghost.style.top = `${y - dy}px`; ghost.style.transform = 'rotate(4deg)'; };
    place(e.clientX, e.clientY);
    document.body.appendChild(ghost);
    const pointer = { x: e.clientX, y: e.clientY };
    const follow = (ev) => { if (ev.clientX || ev.clientY) { place(ev.clientX, ev.clientY); pointer.x = ev.clientX; pointer.y = ev.clientY; } };
    // Near the edge of the board, or of the window, the page scrolls by itself (faster the closer to the edge), so every column can be reached while carrying.
    let frame = requestAnimationFrame(function scroll() {
        const bar = scroller.value?.getBoundingClientRect();
        const edge = 90; const speed = (d) => Math.ceil(Math.min(1, (edge - d) / edge) * 22);
        if (bar && pointer.y > bar.top - 40 && pointer.y < bar.bottom + 40) {
            if (pointer.x < bar.left + edge) scroller.value.scrollLeft -= speed(Math.max(0, pointer.x - bar.left));
            else if (pointer.x > bar.right - edge) scroller.value.scrollLeft += speed(Math.max(0, bar.right - pointer.x));
        }
        if (pointer.y < 70) window.scrollBy(0, -speed(Math.max(0, pointer.y - 10)));
        else if (pointer.y > window.innerHeight - 70) window.scrollBy(0, speed(Math.max(0, window.innerHeight - pointer.y - 10)));
        frame = requestAnimationFrame(scroll);
    });
    // The card that was picked up leaves the screen when it is dropped in another column, so its own "dragend" never comes: the drop (anywhere) ends it too.
    ghostEl = ghost;
    paint(ticket.status);
    const end = () => carrying?.();
    document.addEventListener('dragover', follow, true);
    document.addEventListener('drop', end, true);
    document.addEventListener('dragend', end, true);
    carrying = () => { cancelAnimationFrame(frame); ghostEl = null; document.removeEventListener('dragover', follow, true); document.removeEventListener('drop', end, true); document.removeEventListener('dragend', end, true); ghost.remove(); carrying = null; };
}
/** The card being carried takes the colour of the column it is over (the one it came from, while it is over none). */
function paint(key) {
    if (!ghostEl) return;
    const color = COLUMNS.find(([k]) => k === key)?.[2] || '#cbd5e1';
    Object.assign(ghostEl.style, { borderLeftColor: color, backgroundColor: `color-mix(in srgb, ${color} 14%, var(--surface, white))`, outline: `2px solid ${color}`, transition: 'background-color .15s, border-color .15s, outline-color .15s' });
}
watch(over, (key) => paint(key ?? dragging.value?.from));
const stopCarrying = () => carrying?.();
onBeforeUnmount(stopCarrying);
const shown = computed(() => rows.value.filter((t) => {
    return visible(t) && matches(t, filter.value);
}));

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
async function call(method, url, body, form = false) {
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest', ...(form ? {} : { 'Content-Type': 'application/json' }) };
    const r = await fetch(url, { method, credentials: 'same-origin', headers, body: body === undefined ? undefined : (form ? body : JSON.stringify(body)) });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message ?? Object.values(data.errors ?? {})[0]?.[0] ?? 'That did not work');
    if (method !== 'GET') window.dispatchEvent(new Event('orvyn:saved'));      // saved: nothing is left to discard (see unsavedGuard)
    return data;
}
const reload = () => router.reload({ only: ['tickets', 'summary'], preserveScroll: true });
async function open(id) { try { detail.value = (await call('GET', `/platform/tickets/${id}`)).data; reload(); } catch (e) { toastError(e); } }
async function update(patch, message) { try { detail.value = (await call('PATCH', `/platform/tickets/${detail.value.id}`, patch)).data; message && toast(message); reload(); } catch (e) { toastError(e); } }
async function send(payload, done) {
    busy.value = true;
    const fd = new FormData();
    fd.append('body', payload.body);
    payload.note && fd.append('note', '1');
    payload.files.forEach((f) => fd.append('attachments[]', f));
    try { detail.value = (await call('POST', `/platform/tickets/${detail.value.id}/reply`, fd, true)).data; done(); toast(payload.note ? 'Note added' : 'Reply sent'); reload(); await nextTick(); window.dispatchEvent(new Event('orvyn:saved')); } catch (e) { toastError(e); } finally { busy.value = false; }
}
async function saveSettings() {
    try { await call('PATCH', '/platform/tickets/settings', form.value); settingsOpen.value = false; toast('Settings saved'); router.reload({ only: ['settings'], preserveScroll: true }); } catch (e) { toastError(e); }
}
// a company wrote or something changed: refresh the board, and the ticket being read without disturbing what is typed
watch(() => liveCounts.platformTicketsSig, async () => {
    reload();
    if (!detail.value) return;
    try { const fresh = (await call('GET', `/platform/tickets/${detail.value.id}`)).data; if (fresh.replies.length !== detail.value.replies.length || fresh.status !== detail.value.status) detail.value = fresh; } catch { /* the ticket is still on screen */ }
});
onMounted(() => { if (props.focus) open(Number(props.focus)); });
const finished = computed(() => ['resolved', 'closed'].includes(detail.value?.status));
const CONTEXT = { page: 'Page', browser: 'Browser', screen: 'Window', language: 'Language', role: 'Role', reported_at: 'Reported' };
</script>

<template>
    <Head title="Tickets" />
    <PageHeader title="Tickets" subtitle="Problems reported by the companies' administrators, from open to solved.">
        <button type="button" class="btn-outline" data-testid="tickets-settings" @click="settingsOpen = true"><Settings2 class="size-4" /> Settings</button>
    </PageHeader>

    <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5" data-testid="tickets-summary" aria-label="Tickets in numbers">
        <TicketTile :icon="Inbox" tone="bg-rose-100 text-rose-700" label="Needs us" :value="summary.open" :alert="summary.open > 0" hint="the company is waiting" clickable :active="filter === 'active' && owner !== 'none'" @click="filter = 'active'; owner = 'all'" />
        <TicketTile :icon="UserX" tone="bg-amber-100 text-amber-700" label="Unassigned" :value="summary.unassigned" :alert="summary.unassigned > 0" hint="open, nobody owns it" clickable :active="owner === 'none'" @click="filter = 'active'; owner = owner === 'none' ? 'all' : 'none'" />
        <TicketTile :icon="Hourglass" tone="bg-sky-100 text-sky-700" label="Waiting for the company" :value="summary.waiting" hint="we replied" clickable :active="filter === 'waiting'" @click="filter = 'waiting'" />
        <TicketTile :icon="CheckCheck" tone="bg-emerald-100 text-emerald-700" label="Solved, 7 days" :value="summary.resolved_7d" hint="resolved or closed" />
        <TicketTile :icon="Clock" tone="bg-indigo-100 text-indigo-700" label="Oldest open" :value="`${summary.oldest_open_days} d`" hint="days since it was opened" />
    </section>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-60 !pl-9" placeholder="Find a ticket or company…" aria-label="Find a ticket" /></div>
        <button v-for="[v, l] in (view === 'list' ? FILTERS : [])" :key="v" type="button" class="rounded-full border px-3 py-1 text-xs font-medium transition" :class="filter === v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-500 hover:text-slate-700'" :data-testid="`filter-${v}`" @click="filter = v">{{ l }} <span class="text-slate-400">{{ count(v) }}</span></button>
        <select v-model="owner" class="input !w-auto" aria-label="Whose"><option value="all">Everyone's</option><option value="mine">Mine</option><option value="none">Unassigned</option></select>
        <select v-model="priority" class="input !w-auto" aria-label="Priority"><option value="">Any priority</option><option v-for="p in priorities" :key="p" :value="p">{{ PRIORITY[p][0] }}</option></select>
        <div class="ml-auto inline-flex rounded-xl border border-slate-200 p-1 text-sm" role="group" aria-label="View">
            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1 font-medium" :class="view === 'board' ? 'bg-brand-50 text-brand-700' : 'text-slate-500'" data-testid="view-board" @click="setView('board')"><KanbanSquare class="size-4" /> Board</button>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1 font-medium" :class="view === 'list' ? 'bg-brand-50 text-brand-700' : 'text-slate-500'" data-testid="view-list" @click="setView('list')"><List class="size-4" /> List</button>
        </div>
    </div>

    <!-- board: drag a ticket to another column to change its status -->
    <div v-if="view === 'board'" ref="scroller" class="-mx-4 flex items-stretch gap-4 overflow-x-auto overflow-y-hidden px-4 pb-3 lg:-mx-8 lg:px-8" data-testid="tickets-board">
        <section v-for="[k, label, colour] in COLUMNS" :key="k" class="flex max-h-full w-80 shrink-0 flex-col rounded-xl border border-slate-200/70 bg-slate-100/70 transition" :class="over === k && 'bg-brand-50/60 ring-2 ring-brand-500'" :data-testid="`col-${k}`"
            @dragover.prevent="over = k" @dragleave.self="over = null" @drop.prevent="drop(k)">
            <header class="rounded-t-xl bg-slate-50 px-4 py-3" :style="{ borderTop: `4px solid ${colour}` }">
                <div class="flex items-center justify-between gap-2 text-sm font-semibold"><span class="truncate">{{ label }}</span><span class="badge">{{ cards[k].length }}</span></div>
            </header>
            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                <article v-for="t in (k === 'closed' ? cards[k].slice(0, CLOSED_CAP) : cards[k])" :key="t.id" draggable="true" tabindex="0" role="button" :aria-label="`Open ${t.reference}: ${t.subject}`" data-testid="ticket-card"
                    class="card cursor-grab space-y-2 border-l-4 p-3 text-sm outline-none transition hover:-translate-y-px hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-500 active:cursor-grabbing"
                    :style="{ borderLeftColor: PRIORITY[t.priority][2] }" :class="[dragging?.ticket.id === t.id && 'opacity-40', landed === t.id && 'card-landed']"
                    @click="open(t.id)" @keydown.enter.self="open(t.id)" @dragstart="start($event, t)" @dragend="dragging = null; over = null; stopCarrying()">
                    <div class="flex items-start gap-2"><span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg" :class="CATEGORY_LOOK[t.category][1]" :title="categories[t.category]"><component :is="CATEGORY_LOOK[t.category][0]" class="size-4" /></span>
                        <div class="min-w-0 flex-1"><div class="flex items-start gap-1.5"><span v-if="t.unread" class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-500" title="Unread" /><div class="font-medium leading-snug">{{ t.subject }}</div></div>
                            <div class="mt-0.5 truncate text-xs text-slate-500">{{ t.company }}</div></div></div>
                    <div class="mt-2.5 flex items-center gap-2 text-xs text-slate-400"><span class="font-mono">{{ t.reference }}</span><span class="ml-auto" :title="fmtDate(t.last_activity_at, true)">{{ fmtRelative(t.last_activity_at) }}</span>
                        <Avatar v-if="t.assignee" :name="t.assignee" size="sm" :title="t.assignee" /><span v-else class="grid size-6 place-items-center rounded-full border border-dashed border-slate-300 text-[10px]" title="Unassigned">?</span></div>
                </article>
                <p v-if="!cards[k].length" class="rounded-lg border border-dashed border-slate-300 py-8 text-center text-xs text-slate-400"><component :is="{ open: Inbox, waiting: Hourglass, resolved: CheckCheck, closed: CheckCircle2 }[k]" class="mx-auto mb-1.5 size-5 text-slate-300" />{{ k === 'open' ? 'Inbox zero. Nicely done.' : 'Drop tickets here' }}</p>
                <button v-if="k === 'closed' && cards[k].length > CLOSED_CAP" type="button" class="w-full py-1 text-xs text-brand-700 hover:underline" @click="setView('list'); filter = 'closed'">{{ cards[k].length - CLOSED_CAP }} more in the list</button>
            </div>
        </section>
    </div>

    <div v-else class="card table-scroll" data-testid="tickets-table">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Ticket</th><th class="px-3 py-3">Company</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Priority</th><th class="px-3 py-3">Owner</th><th class="px-3 py-3 text-right">Last activity</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="t in shown" :key="t.id" class="msg-row cursor-pointer transition hover:bg-slate-50" data-testid="ticket-row" @click="open(t.id)">
                    <td class="px-4 py-3"><div class="flex items-center gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-xl" :class="CATEGORY_LOOK[t.category][1]" :title="categories[t.category]"><component :is="CATEGORY_LOOK[t.category][0]" class="size-4" /></span>
                        <div class="min-w-0"><div class="flex items-center gap-2 font-medium" :class="t.unread && 'text-slate-900'"><span v-if="t.unread" class="size-2 shrink-0 rounded-full bg-brand-500" title="Unread" /><span>{{ t.subject }}</span></div><div class="text-xs text-slate-400"><span class="font-mono">{{ t.reference }}</span> · {{ categories[t.category] }}</div></div></div></td>
                    <td class="px-3 py-3 text-slate-600"><div class="flex items-center gap-2"><Avatar :name="t.company" size="sm" />{{ t.company }}</div><div class="ml-8 text-xs text-slate-400">{{ t.opened_by }}</div></td>
                    <td class="px-3 py-3"><span class="badge" :class="PLATFORM_STATUS[t.status][1]">{{ PLATFORM_STATUS[t.status][0] }}</span></td>
                    <td class="px-3 py-3"><span class="inline-flex items-center gap-1.5" :class="PRIORITY[t.priority][1]"><span class="size-2 rounded-full" :style="{ background: PRIORITY[t.priority][2] }" />{{ PRIORITY[t.priority][0] }}</span></td>
                    <td class="px-3 py-3 text-slate-600">{{ t.assignee ?? 'Nobody' }}</td>
                    <td class="px-3 py-3 text-right text-slate-500" :title="fmtDate(t.last_activity_at, true)">{{ fmtRelative(t.last_activity_at) }}</td>
                </tr>
                <tr v-if="!shown.length"><td colspan="6" class="px-5 py-12 text-center text-slate-400">{{ rows.length ? 'No ticket matches.' : 'No tickets yet.' }}</td></tr>
            </tbody>
        </table>
    </div>

    <!-- one ticket: the conversation on the left, what we know and what we can change on the right -->
    <Modal v-if="detail" :title="`${detail.reference}: ${detail.subject}`" width="max-w-6xl" fill @close="detail = null">
        <div class="grid min-h-0 flex-1 gap-6 lg:grid-cols-[minmax(0,1fr)_17rem]" data-testid="ticket-detail">
            <div class="flex min-h-0 min-w-0 flex-col gap-4">
                <TicketThread :replies="detail.replies" mine="platform" scroll />
                <TicketComposer class="shrink-0" :busy="busy" allow-note placeholder="Reply to the company…" @send="send" />
            </div>
            <aside class="min-h-0 space-y-4 overflow-y-auto pr-1 text-sm">
                <div class="flex flex-wrap items-center gap-2"><span class="badge" :class="PLATFORM_STATUS[detail.status][1]" data-testid="detail-status">{{ PLATFORM_STATUS[detail.status][0] }}</span><span class="text-xs text-slate-400">{{ PLATFORM_STATUS[detail.status][2] }}</span></div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="detail.status !== 'resolved' && detail.status !== 'closed'" type="button" class="btn-primary !py-1.5" data-testid="detail-resolve" @click="update({ status: 'resolved' }, 'Marked as resolved. The company has been told.')"><CheckCircle2 class="size-4" /> Resolve</button>
                    <button v-if="detail.status !== 'closed'" type="button" class="btn-outline !py-1.5" data-testid="detail-close" @click="update({ status: 'closed' }, 'Closed')">Close</button>
                    <button v-if="finished" type="button" class="btn-outline !py-1.5" data-testid="detail-reopen" @click="update({ status: 'open' }, 'Reopened')"><RotateCcw class="size-4" /> Reopen</button>
                </div>
                <dl class="space-y-3">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Company</dt><dd><Link :href="`/platform/companies/${detail.company_id}`" class="inline-flex items-center gap-1 text-brand-700 hover:underline">{{ detail.company }} <ExternalLink class="size-3" /></Link></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Reported by</dt><dd>{{ detail.opened_by.name }}<div class="text-xs text-slate-400">{{ detail.opened_by.email }}</div></dd></div>
                    <div><label class="text-xs uppercase tracking-wide text-slate-400" for="t-owner">Owner</label><select id="t-owner" :value="detail.assignee_id ?? ''" class="input mt-1" data-testid="detail-owner" @change="update({ assignee_id: $event.target.value || null })"><option value="">Nobody</option><option v-for="a in admins" :key="a.id" :value="a.id">{{ a.name }}{{ a.id === me ? ' (me)' : '' }}</option></select></div>
                    <div class="grid grid-cols-2 gap-2"><div><label class="text-xs uppercase tracking-wide text-slate-400" for="t-prio">Priority</label><select id="t-prio" :value="detail.priority" class="input mt-1" data-testid="detail-priority" @change="update({ priority: $event.target.value })"><option v-for="p in priorities" :key="p" :value="p">{{ PRIORITY[p][0] }}</option></select></div>
                        <div><label class="text-xs uppercase tracking-wide text-slate-400" for="t-cat">About</label><select id="t-cat" :value="detail.category" class="input mt-1" @change="update({ category: $event.target.value })"><option v-for="(l, k) in categories" :key="k" :value="k">{{ l }}</option></select></div></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Dates</dt><dd class="text-slate-600">Opened {{ fmtDate(detail.created_at, true) }}<template v-if="detail.first_response_at"><br>First answer {{ fmtRelative(detail.first_response_at) }}</template><template v-if="detail.reopened_count"><br>Reopened {{ detail.reopened_count }}×</template></dd></div>
                    <div v-if="detail.thread_id"><dt class="text-xs uppercase tracking-wide text-slate-400">Came from</dt><dd><Link :href="`/platform/messages?thread=${detail.thread_id}`" class="text-brand-700 hover:underline">The conversation in Messages</Link></dd></div>
                    <div v-if="detail.context"><dt class="text-xs uppercase tracking-wide text-slate-400">Where they were</dt>
                        <dd class="mt-1 space-y-1 rounded-lg bg-slate-50 p-2.5 text-xs text-slate-600"><div v-for="(label, k) in CONTEXT" :key="k" v-show="detail.context[k]" class="flex gap-2"><span class="w-14 shrink-0 text-slate-400">{{ label }}</span><span class="min-w-0 break-all">{{ k === 'reported_at' ? fmtDate(detail.context[k], true) : detail.context[k] }}</span></div></dd></div>
                </dl>
            </aside>
        </div>
    </Modal>

    <Modal v-if="settingsOpen" title="Ticket settings" @close="settingsOpen = false">
        <form id="ticket-settings" class="space-y-4" @submit.prevent="saveSettings">
            <div><label class="label" for="auto-close">Close a resolved ticket by itself after (days)</label><input id="auto-close" v-model.number="form.auto_close_days" type="number" min="1" max="60" class="input !w-24" data-testid="set-auto-close" /><p class="mt-1 text-xs text-slate-500">When nobody has written on it since it was resolved. The company can always reopen it.</p></div>
            <label class="flex cursor-pointer items-start gap-2 text-sm"><input v-model="form.email_company" type="checkbox" class="mt-0.5 rounded" data-testid="set-email-company" /><span><span class="font-medium">E-mail the company's administrators</span><span class="block text-xs text-slate-500">When we reply or resolve a ticket. They always get a notice in the app. Each of us chooses whether to be e-mailed about new tickets under E-mail notifications.</span></span></label>
        </form>
        <template #footer><button class="btn-ghost" @click="settingsOpen = false">Cancel</button><button class="btn-primary" form="ticket-settings" data-testid="save-ticket-settings">Save</button></template>
    </Modal>
</template>

<style scoped>
/* a dropped ticket settles into its place: it comes in slightly lifted and tilted, overshoots a little, and rests */
.card-landed { animation: card-land 0.55s cubic-bezier(0.22, 1.2, 0.36, 1); }
@keyframes card-land {
    0% { transform: translateY(-14px) rotate(4deg) scale(1.04); box-shadow: 0 16px 32px rgba(15, 23, 42, 0.3); }
    60% { transform: translateY(2px) rotate(-0.6deg) scale(0.995); box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12); }
    100% { transform: none; box-shadow: none; }
}
@media (prefers-reduced-motion: reduce) { .card-landed { animation: none; } }
</style>
