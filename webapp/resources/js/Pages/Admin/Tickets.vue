<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Archive, CheckCircle2, Headset, LayoutGrid, LayoutList, LifeBuoy, List, MessageSquareReply, Plus, RotateCcw, Search } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import TicketComposer from '@/Components/support/TicketComposer.vue';
import TicketForm from '@/Components/support/TicketForm.vue';
import TicketThread from '@/Components/support/TicketThread.vue';
import { api } from '@/lib/api';
import { liveCounts } from '@/lib/live';
import { useStored } from '@/lib/stored';
import { CATEGORY, CATEGORY_LOOK, COMPANY_STATUS, PRIORITY } from '@/lib/support';
import { confirmDialog, fmtDate, fmtRelative, run, toast, toastError } from '@/lib/ui';

// Support tickets of the company: report a problem, follow it until it is solved. Every administrator of the company sees every ticket and can write on it.
const tickets = ref(null);
// how the tickets are laid out, kept in this browser: 'detailed' (a row with everything), 'list' (one slim line each) or 'boxes' (a grid of cards)
const layout = useStored('tickets:layout', 'detailed');
const LAYOUTS = [['detailed', LayoutList, 'Detailed'], ['list', List, 'List'], ['boxes', LayoutGrid, 'Boxes']];
const filter = ref('active');
const q = ref('');
const opening = ref(false);
const detail = ref(null);
const busy = ref(false);
const composer = ref(null);

const FILTERS = [['active', 'Active'], ['waiting', 'Waiting for you'], ['resolved', 'Resolved'], ['closed', 'Closed'], ['all', 'All']];
const failed = ref('');
async function load() { try { tickets.value = (await api.get('/support-tickets')).data; failed.value = ''; } catch (e) { failed.value = e.status === 403 ? 'Only administrators of the company can use support tickets.' : `Could not load the tickets: ${e.message}`; } }
onMounted(async () => {
    await load();
    const id = Number(new URLSearchParams(location.search).get('ticket'));
    if (id) openTicket(id);
});

// something changed on a ticket of the company (a reply from the platform, a status): refresh the list, and the ticket being read without disturbing what is typed
watch(() => liveCounts.ticketsSig, async () => {
    await load();
    if (!detail.value) return;
    try { const fresh = (await api.get(`/support-tickets/${detail.value.id}`)).data; if (fresh.replies.length !== detail.value.replies.length || fresh.status !== detail.value.status) detail.value = fresh; } catch { /* the ticket is still on screen */ }
});
const count = (s) => (tickets.value ?? []).filter((t) => t.status === s).length;
const shown = computed(() => (tickets.value ?? []).filter((t) => {
    const term = q.value.trim().toLowerCase();
    if (term && !`${t.reference} ${t.subject}`.toLowerCase().includes(term)) return false;
    return { all: true, active: ['open', 'waiting'].includes(t.status), waiting: t.status === 'waiting', resolved: t.status === 'resolved', closed: t.status === 'closed' }[filter.value];
}));
const filterCount = (k) => (tickets.value ?? []).filter((t) => ({ all: true, active: ['open', 'waiting'].includes(t.status), waiting: t.status === 'waiting', resolved: t.status === 'resolved', closed: t.status === 'closed' })[k]).length;

async function openTicket(id) {
    try { detail.value = (await api.get(`/support-tickets/${id}`)).data; load(); } catch (e) { toastError(e); }
}
// the editor is emptied after the save, so what is on screen counts as saved only once that has happened (see unsavedGuard)
const settled = async () => { await nextTick(); window.dispatchEvent(new Event('orvyn:saved')); };
async function send(payload, done) {
    busy.value = true;
    const fd = new FormData();
    fd.append('body', payload.body);
    payload.files.forEach((f) => fd.append('attachments[]', f));
    try { detail.value = (await api.postForm(`/support-tickets/${detail.value.id}/replies`, fd)).data; done(); load(); await settled(); } catch (e) { toastError(e); } finally { busy.value = false; }
}
async function close() {
    if (!(await confirmDialog({ title: `Close ${detail.value.reference}?`, message: 'Close it when the problem is solved. You can reopen it later.', confirmLabel: 'Close the ticket' }))) return;
    const d = await run(() => api.post(`/support-tickets/${detail.value.id}/close`), 'Ticket closed');
    if (d) { detail.value = d.data; load(); }
}
async function reopen() {
    const d = await run(() => api.post(`/support-tickets/${detail.value.id}/reopen`), 'Ticket reopened');
    if (d) { detail.value = d.data; load(); }
}
const saved = (t) => { opening.value = false; toast(`Ticket ${t.reference} is with support. Every administrator of your company can follow it.`); load(); openTicket(t.id); };
const finished = computed(() => ['resolved', 'closed'].includes(detail.value?.status));
</script>

<template>
    <AdminPage standalone title="Support tickets" subtitle="Report a problem and follow it until it is solved. Every administrator of your company sees these.">
        <template #actions><button class="btn-primary" data-testid="report-problem" @click="opening = true"><Plus class="size-4" /> Report a problem</button></template>

        <div v-if="failed" class="card p-6 text-sm text-red-600" data-testid="tickets-error">{{ failed }}</div>
        <div v-else-if="!tickets" class="text-sm text-slate-400">Loading…</div>
        <template v-else>
            <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4" data-testid="tickets-summary">
                <TicketTile :icon="Headset" tone="bg-sky-100 text-sky-700" label="With support" :value="count('open')" hint="the platform will answer" clickable :active="filter === 'active'" @click="filter = 'active'" />
                <TicketTile :icon="MessageSquareReply" tone="bg-amber-100 text-amber-700" label="Waiting for you" :value="count('waiting')" :hint="count('waiting') ? 'the platform replied' : 'nothing to answer'" :alert="count('waiting') > 0" clickable :active="filter === 'waiting'" @click="filter = 'waiting'" />
                <TicketTile :icon="CheckCircle2" tone="bg-emerald-100 text-emerald-700" label="Resolved" :value="count('resolved')" hint="close by themselves if quiet" clickable :active="filter === 'resolved'" @click="filter = 'resolved'" />
                <TicketTile :icon="Archive" tone="bg-slate-100 text-slate-500" label="Closed" :value="count('closed')" hint="all time" clickable :active="filter === 'closed'" @click="filter = 'closed'" />
            </section>

            <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-60 !pl-9" placeholder="Find a ticket…" aria-label="Find a ticket" /></div>
                <button v-for="[v, l] in FILTERS" :key="v" type="button" class="rounded-full border px-3 py-1 text-xs font-medium transition" :class="filter === v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-500 hover:text-slate-700'" :data-testid="`filter-${v}`" @click="filter = v">{{ l }} <span class="text-slate-400">{{ filterCount(v) }}</span></button>
                <div class="ml-auto inline-flex rounded-xl border border-slate-200 p-1 text-sm" role="group" aria-label="Layout">
                    <button v-for="[v, icon, l] in LAYOUTS" :key="v" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-medium transition" :class="layout === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :aria-pressed="layout === v" :title="l" :data-testid="`layout-${v}`" @click="layout = v"><component :is="icon" class="size-4" /><span class="hidden sm:inline">{{ l }}</span></button>
                </div>
            </div>

            <!-- the same tickets in three layouts -->
            <div v-if="shown.length && layout === 'detailed'" class="card divide-y divide-slate-100 overflow-hidden" data-testid="tickets-list">
                <button v-for="t in shown" :key="t.id" type="button" class="msg-row relative flex w-full items-start gap-4 py-4 pl-6 pr-5 text-left transition hover:bg-slate-50" data-testid="ticket-row" @click="openTicket(t.id)">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="CATEGORY_LOOK[t.category][1]" :title="CATEGORY[t.category]"><component :is="CATEGORY_LOOK[t.category][0]" class="size-5" /></span>
                    <span class="absolute inset-y-0 left-0 w-1.5" :style="{ background: PRIORITY[t.priority][2] }" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2"><span v-if="t.unread" class="size-2 shrink-0 rounded-full bg-brand-500" title="New reply" /><span class="font-mono text-xs text-slate-400">{{ t.reference }}</span><span class="truncate font-medium" :class="t.unread && 'text-slate-900'">{{ t.subject }}</span></div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400"><span>{{ CATEGORY[t.category] }}</span><span :class="PRIORITY[t.priority][1]">{{ PRIORITY[t.priority][0] }} priority</span><span>opened by {{ t.opened_by }} {{ fmtRelative(t.created_at) }}</span><span>{{ t.replies }} {{ t.replies === 1 ? 'message' : 'messages' }}</span></div>
                    </div>
                    <div class="shrink-0 text-right"><span class="badge" :class="COMPANY_STATUS[t.status][1]">{{ COMPANY_STATUS[t.status][0] }}</span><div class="mt-1 text-xs text-slate-400" :title="fmtDate(t.last_activity_at, true)">{{ fmtRelative(t.last_activity_at) }}</div></div>
                </button>
            </div>

            <div v-else-if="shown.length && layout === 'list'" class="card table-scroll" data-testid="tickets-list">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2.5">Ticket</th><th class="px-3 py-2.5">About</th><th class="px-3 py-2.5">Priority</th><th class="px-3 py-2.5">Status</th><th class="px-3 py-2.5">Opened by</th><th class="px-3 py-2.5 text-right">Last activity</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="t in shown" :key="t.id" class="msg-row cursor-pointer transition hover:bg-slate-50" data-testid="ticket-row" @click="openTicket(t.id)">
                            <td class="px-4 py-2"><div class="flex items-center gap-2"><span v-if="t.unread" class="size-2 shrink-0 rounded-full bg-brand-500" title="New reply" /><span class="font-mono text-xs text-slate-400">{{ t.reference }}</span><span class="truncate font-medium" :class="t.unread && 'text-slate-900'">{{ t.subject }}</span></div></td>
                            <td class="px-3 py-2 text-slate-500"><span class="inline-flex items-center gap-1.5"><component :is="CATEGORY_LOOK[t.category][0]" class="size-3.5 text-slate-400" />{{ CATEGORY[t.category] }}</span></td>
                            <td class="px-3 py-2"><span class="inline-flex items-center gap-1.5" :class="PRIORITY[t.priority][1]"><span class="size-2 rounded-full" :style="{ background: PRIORITY[t.priority][2] }" />{{ PRIORITY[t.priority][0] }}</span></td>
                            <td class="px-3 py-2"><span class="badge" :class="COMPANY_STATUS[t.status][1]">{{ COMPANY_STATUS[t.status][0] }}</span></td>
                            <td class="px-3 py-2 text-slate-500">{{ t.opened_by }}</td>
                            <td class="px-3 py-2 text-right text-slate-500" :title="fmtDate(t.last_activity_at, true)">{{ fmtRelative(t.last_activity_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else-if="shown.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="tickets-list">
                <button v-for="t in shown" :key="t.id" type="button" class="card msg-row group relative flex flex-col overflow-hidden p-4 pt-5 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-md" data-testid="ticket-row" @click="openTicket(t.id)">
                    <span class="absolute inset-x-0 top-0 h-1" :style="{ background: PRIORITY[t.priority][2] }" aria-hidden="true" />
                    <div class="flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="CATEGORY_LOOK[t.category][1]" :title="CATEGORY[t.category]"><component :is="CATEGORY_LOOK[t.category][0]" class="size-5" /></span>
                        <div class="min-w-0 flex-1"><div class="flex items-center gap-1.5 font-mono text-xs text-slate-400"><span v-if="t.unread" class="size-2 shrink-0 rounded-full bg-brand-500" title="New reply" />{{ t.reference }}</div>
                            <div class="mt-0.5 line-clamp-2 font-medium leading-snug" :class="t.unread && 'text-slate-900'">{{ t.subject }}</div></div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400"><span>{{ CATEGORY[t.category] }}</span><span :class="PRIORITY[t.priority][1]">{{ PRIORITY[t.priority][0] }} priority</span><span>{{ t.replies }} {{ t.replies === 1 ? 'message' : 'messages' }}</span></div>
                    <div class="mt-auto flex items-center gap-2 pt-4"><span class="badge" :class="COMPANY_STATUS[t.status][1]">{{ COMPANY_STATUS[t.status][0] }}</span><span class="ml-auto truncate text-xs text-slate-400" :title="fmtDate(t.last_activity_at, true)">{{ t.opened_by }} · {{ fmtRelative(t.last_activity_at) }}</span></div>
                </button>
            </div>

            <div v-else class="card" data-testid="tickets-list">
                <div class="msg-row px-5 py-14 text-center">
                    <span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-sky-100 text-sky-700"><LifeBuoy class="size-8" /></span>
                    <p class="font-semibold text-slate-700">{{ tickets.length ? 'No ticket matches' : 'Nothing to report, nice' }}</p>
                    <p v-if="!tickets.length" class="mx-auto mt-1 max-w-sm text-sm text-slate-400">When something does not work, report it here and the platform will help. You will see every answer in the ticket.</p>
                    <button v-if="!tickets.length" type="button" class="btn-primary mt-4" @click="opening = true"><Plus class="size-4" /> Report a problem</button>
                </div>
            </div>
        </template>

        <TicketForm v-if="opening" @close="opening = false" @saved="saved" />

        <Modal v-if="detail" :title="`${detail.reference}: ${detail.subject}`" width="max-w-4xl" fill @close="detail = null">
            <div class="flex min-h-0 flex-1 flex-col gap-4" data-testid="ticket-detail">
                <div class="flex shrink-0 flex-wrap items-center gap-2 text-sm">
                    <span class="badge" :class="COMPANY_STATUS[detail.status][1]" data-testid="detail-status">{{ COMPANY_STATUS[detail.status][0] }}</span>
                    <span class="text-slate-500">{{ COMPANY_STATUS[detail.status][2] }}</span>
                    <span v-if="detail.status === 'resolved'" class="text-xs text-slate-400">It closes by itself after {{ detail.auto_close_days }} days without a reply.</span>
                    <span class="ml-auto flex gap-2">
                        <button v-if="finished" type="button" class="btn-outline !py-1.5" data-testid="ticket-reopen" @click="reopen"><RotateCcw class="size-4" /> Reopen</button>
                        <button v-if="detail.status !== 'closed'" type="button" class="btn-outline !py-1.5" data-testid="ticket-close" @click="close"><CheckCircle2 class="size-4" /> It is solved, close it</button>
                    </span>
                </div>
                <p class="shrink-0 text-xs text-slate-400">{{ CATEGORY[detail.category] }} · {{ PRIORITY[detail.priority][0] }} priority · opened by {{ detail.opened_by }} on {{ fmtDate(detail.created_at, true) }}</p>
                <TicketThread :replies="detail.replies" mine="tenant" scroll />
                <div class="shrink-0">
                    <p v-if="finished" class="mb-2 text-xs text-slate-500">Writing here reopens the ticket.</p>
                    <TicketComposer ref="composer" :busy="busy" :placeholder="finished ? 'Still a problem? Tell us…' : 'Write your reply…'" @send="send" />
                </div>
            </div>
        </Modal>
    </AdminPage>
</template>
