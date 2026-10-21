<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Activity, ArrowDownRight, ArrowUpRight, Download, FileUp, ListPlus, Mail, MessageSquare, Pencil, Plus, RefreshCw, Trash2, UserPlus } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import ContactShow from './Contacts/Show.vue';
import TaskDetail from '@/Components/TaskDetail.vue';
import WidgetResults from '@/Components/WidgetResults.vue';
import EmailComposer from './Contacts/partials/EmailComposer.vue';
import MergeFieldMenu from '@/Components/MergeFieldMenu.vue';
import { insertAtCursor } from '@/lib/mergeFields';
import GettingStarted from '@/Components/GettingStarted.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { api } from '@/lib/api';
import { useFit } from '@/lib/fit';
import { confirmDialog, fmtDate, fmtDue, fmtRelative, run } from '@/lib/ui';

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.is_admin);
const d = ref(null);
const hour = new Date().getHours();
const greeting = computed(() => `${hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'}, ${(page.props.auth.user.name ?? '').split(' ')[0]}`);
const today = new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
// change against the previous 30 days: {text, up} or null when there is nothing to compare
function trend(key) {
    const prev = d.value?.previous?.[key]; const now = Number(d.value?.[key]);
    if (prev === undefined || (!prev && !now)) return null;
    if (!prev) return { text: 'New', up: true };
    const pct = Math.round(((now - prev) / prev) * 100);
    return { text: `${pct > 0 ? '+' : ''}${pct}%`, up: pct >= 0, flat: pct === 0 };
}
const activityLabel = (t) => t.split('.').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
const widgets = ref([]);
const bottom = ref(null);                   // the three lists at the foot: they fill what is left of the window and scroll inside themselves, so the page does not
useFit(bottom, { min: 300, gap: 34, from: 1024, also: [() => widgets.value.length, () => d.value?.setup, d] });
const data = ref({});          // widget id -> result
const queries = ref([]);
const modal = ref(null);       // {type:'widget'|'email'|'sms', widget?}
const draft = ref({});

onMounted(async () => {
    d.value = (await api.get('/dashboard')).data;
    await loadWidgets();
    queries.value = (await api.get('/queries')).data;
});

async function loadWidgets() {
    widgets.value = (await api.get('/widgets')).data;
    widgets.value.forEach(loadData);
}
async function loadData(w) {
    data.value[w.id] = { loading: true };
    try { data.value[w.id] = await api.get(`/widgets/${w.id}/data`, { limit: 8 }); } catch (e) { data.value[w.id] = { error: e.message }; }
}

const viewing = ref(null);       // the widget whose every result is open in a wide pop-up
const openTask = ref(null);      // an upcoming task that was clicked: {task, contactId}
const refreshDashboard = async () => { d.value = (await api.get('/dashboard')).data; };       // a task may have been completed or changed
const closeTask = () => { openTask.value = null; refreshDashboard(); };
async function toggleLoose(t) { await run(() => api.patch(`/tasks/${t.id}`, { completed: !t.completed_at }), t.completed_at ? undefined : 'Task completed'); closeTask(); }
async function removeLoose(t) {
    if (!(await confirmDialog({ title: `Delete “${t.title}”?`, danger: true, confirmLabel: 'Delete' }))) return;
    if (await run(() => api.delete(`/tasks/${t.id}`), 'Task deleted')) closeTask();
}
const openUpcoming = (t) => { openTask.value = { task: t, contactId: t.contact_id }; };

const stat = (label, key, suffix = '') => ({ label, key, suffix });
// where each tile leads: the list it counts. (E-mail sending is followed under Blast progress, which only administrators have.)
const TILE_LINKS = { contacts: '/contacts', new_contacts_30d: '/contacts?added=30', emails_sent_30d: '/admin/blast-progress', open_rate_30d: '/admin/blast-progress', my_open_tasks: '/tasks?status=open', my_overdue_tasks: '/tasks?status=overdue' };
const tileLink = (key) => (key.startsWith('emails_') || key.startsWith('open_rate') ? (isAdmin.value ? TILE_LINKS[key] : null) : TILE_LINKS[key]);
const stats = [stat('Contacts', 'contacts'), stat('New (30d)', 'new_contacts_30d'), stat('Emails sent (30d)', 'emails_sent_30d'), stat('Open rate', 'open_rate_30d', '%'), stat('My open tasks', 'my_open_tasks'), stat('Overdue', 'my_overdue_tasks')];

function openWidget(w = null) { draft.value = w ? { id: w.id, name: w.name, saved_query_id: w.saved_query_id } : { name: '', saved_query_id: queries.value[0]?.id ?? '', shared: false }; modal.value = { type: 'widget' }; }
async function saveWidget() {
    const body = { name: draft.value.name, saved_query_id: draft.value.saved_query_id, shared: draft.value.shared };
    const ok = await run(() => (draft.value.id ? api.patch(`/widgets/${draft.value.id}`, body) : api.post('/widgets', body)), 'Widget saved');
    if (ok) { modal.value = null; loadWidgets(); }
}
async function removeWidget(w) {
    if (!(await confirmDialog({ title: `Remove “${w.name}”?`, message: 'The saved query itself is not deleted.', danger: true, confirmLabel: 'Remove' }))) return;
    await run(() => api.delete(`/widgets/${w.id}`), 'Removed'); loadWidgets();
}
const smsEl = ref(null);
async function addSmsField(token) {
    const r = insertAtCursor(smsEl.value, draft.value.body ?? '', token);
    draft.value.body = r.value;
    await nextTick();
    smsEl.value?.focus();
    smsEl.value?.setSelectionRange(r.caret, r.caret);
}
function openBatch(type, w) { draft.value = { subject: '', html: '', body: '' }; modal.value = { type, widget: w }; }
async function sendBatch(composed = null) {
    const w = modal.value.widget; const email = modal.value.type === 'email';
    const body = email ? composed : { body: draft.value.body };
    if (!(await confirmDialog({ title: `${email ? 'Email' : 'Text'} ${data.value[w.id]?.total ?? 'all'} contacts?`, message: 'Opted-out and suppressed contacts are skipped automatically.', confirmLabel: 'Queue messages' }))) return;
    if (await run(() => api.post(`/widgets/${w.id}/batch/${modal.value.type}`, body), 'Messages are being queued. Follow the progress under Blast progress.')) modal.value = null;
}
const target = (w, r) => (r.contact_id ? `/contacts/${r.contact_id}` : r.contacts?.[0] ? `/contacts/${r.contacts[0].id}` : r.data_object_id ? `/objects/${r.data_object_id}` : null);
const cell = (v) => (Array.isArray(v) ? v.join(', ') : v);
</script>

<template>
    <Head title="Dashboard" />
    <PageHeader title="Dashboard" :subtitle="`${greeting} · ${today}`">
        <nav class="flex flex-wrap gap-2" aria-label="Quick actions">
            <Link href="/contacts?new=1" class="btn-outline"><UserPlus class="size-4" /> Create contact</Link>
            <Link href="/tasks?new=1" class="btn-outline"><ListPlus class="size-4" /> Create task</Link>
            <Link v-if="isAdmin" href="/admin/data-tools" class="btn-outline"><FileUp class="size-4" /> Import data</Link>
        </nav>
        <button class="btn-primary" @click="openWidget()"><Plus class="size-4" /> Add widget</button>
    </PageHeader>

    <div v-if="!d" class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6" aria-busy="true" aria-label="Loading">
        <div v-for="n in 6" :key="n" class="card space-y-3 p-4"><span class="block h-3 w-2/3 animate-pulse rounded bg-slate-100" /><span class="block h-7 w-1/3 animate-pulse rounded bg-slate-100" /></div>
    </div>
    <template v-else>
        <GettingStarted v-if="d.setup" :setup="d.setup" @hide="d.setup = null" />
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
            <component :is="tileLink(s.key) ? Link : 'div'" v-for="s in stats" :key="s.key" :href="tileLink(s.key) || undefined" class="card group block p-3" :class="tileLink(s.key) && 'transition hover:border-brand-500 hover:shadow-sm'" :data-testid="`tile-${s.key}`" :title="tileLink(s.key) ? `Open ${s.label}` : undefined">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ s.label }}</div>
                <div class="mt-1 text-2xl font-semibold" :class="s.key === 'my_overdue_tasks' && d[s.key] ? 'text-red-600' : ''">{{ Number(d[s.key]).toLocaleString() }}{{ s.suffix }}</div>
                <div v-if="trend(s.key)" class="mt-1.5 flex items-center gap-1 text-xs" :class="trend(s.key).flat ? 'text-slate-400' : trend(s.key).up ? 'text-emerald-600' : 'text-red-600'" :title="'Compared with the previous 30 days'">
                    <component :is="trend(s.key).up ? ArrowUpRight : ArrowDownRight" class="size-3.5" />{{ trend(s.key).text }} <span class="text-slate-400">vs prev. 30d</span>
                </div>
                <div v-if="tileLink(s.key)" class="mt-1.5 text-xs text-brand-700 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100 max-md:opacity-100">View →</div>
            </component>
        </div>

        <!-- query widgets -->
        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <section v-for="w in widgets" :key="w.id" class="card flex flex-col">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                    <div class="min-w-0"><h2 class="truncate text-sm font-semibold">{{ w.name }}</h2>
                        <p class="text-xs text-slate-400">{{ data[w.id]?.total != null ? data[w.id].total.toLocaleString() + ' results' : '' }}<span v-if="w.user_id === null"> · shared</span></p></div>
                    <div class="flex shrink-0">
                        <button class="btn-ghost !px-1.5" title="Reload" @click="loadData(w)"><RefreshCw class="size-4" /></button>
                        <a :href="`/files/widgets/${w.id}/export`" class="btn-ghost !px-1.5" title="Download CSV"><Download class="size-4" /></a>
                        <button class="btn-ghost !px-1.5" title="Email all" @click="openBatch('email', w)"><Mail class="size-4" /></button>
                        <button class="btn-ghost !px-1.5" title="Text all" @click="openBatch('sms', w)"><MessageSquare class="size-4" /></button>
                        <button v-if="isAdmin || w.user_id" class="btn-ghost !px-1.5" title="Edit" @click="openWidget(w)"><Pencil class="size-4" /></button>
                        <button v-if="isAdmin || w.user_id" class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Remove" @click="removeWidget(w)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
                <div class="max-h-60 overflow-auto">
                    <p v-if="data[w.id]?.loading" class="px-5 py-6 text-sm text-slate-400">Loading…</p>
                    <p v-else-if="data[w.id]?.error" class="px-5 py-6 text-sm text-red-600">{{ data[w.id].error }}</p>
                    <table v-else-if="data[w.id]?.rows" class="w-full text-sm">
                        <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th v-for="c in data[w.id].columns" :key="c.key" class="px-4 py-2">{{ c.label }}</th><th /></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in data[w.id].rows" :key="r.id" class="hover:bg-slate-50"><td v-for="c in data[w.id].columns" :key="c.key" class="px-4 py-2">{{ cell(r.values[c.key]) }}</td>
                                <td class="px-3 text-right"><Link v-if="target(w, r)" :href="target(w, r)" class="text-xs text-brand-700 hover:underline">Open</Link></td></tr>
                            <tr v-if="!data[w.id].rows.length"><td :colspan="data[w.id].columns.length + 1" class="px-5 py-8 text-center text-slate-400">Nothing matches right now.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="data[w.id]?.total > data[w.id]?.rows?.length" class="border-t border-slate-100 px-5 py-2.5 text-right">
                    <button type="button" class="text-sm font-medium text-brand-700 hover:underline" data-testid="widget-view-all" @click="viewing = w">View all {{ data[w.id].total.toLocaleString() }} results</button>
                </div>
            </section>
        </div>

        <div ref="bottom" class="mt-4 grid min-h-0 gap-4 lg:grid-cols-3">
            <section class="card flex min-h-72 flex-col overflow-hidden lg:min-h-0">
                <h2 class="card-title shrink-0">Upcoming tasks</h2>
                <ul class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <li v-for="t in d.upcoming_tasks" :key="t.id" class="flex items-center justify-between px-5 py-3 text-sm">
                        <div class="min-w-0"><button type="button" class="block max-w-full truncate text-left font-medium hover:text-brand-700 hover:underline" data-testid="upcoming-task" @click="openUpcoming(t)">{{ t.title }}</button>
                            <Link v-if="t.contact" :href="`/contacts/${t.contact_id}`" class="text-xs text-brand-600">{{ t.contact.first_name }} {{ t.contact.last_name }}</Link></div>
                        <span class="badge">{{ t.due_at ? fmtDue(t.due_at) : 'No date' }}</span>
                    </li>
                    <li v-if="!d.upcoming_tasks.length" class="px-5 py-6 text-center text-sm text-slate-400">Nothing due. 🎉</li>
                </ul>
            </section>
            <section class="card flex min-h-72 flex-col overflow-hidden lg:min-h-0">
                <h2 class="card-title flex shrink-0 items-center gap-2"><Activity class="size-4 text-slate-400" /> Recent activity</h2>
                <ul class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <li v-for="a in d.recent_activity" :key="a.id" class="px-5 py-3 text-sm">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><span class="font-medium">{{ activityLabel(a.type) }}</span>
                            <Link v-if="a.contact" :href="`/contacts/${a.contact.id}`" class="ml-1 text-brand-600 hover:underline">{{ a.contact.name }}</Link>
                            <div v-if="a.summary" class="truncate text-xs text-slate-500">{{ a.summary }}</div></div>
                            <span class="shrink-0 text-xs text-slate-400" :title="fmtDate(a.at, true)">{{ fmtRelative(a.at) }}</span></div>
                        <div v-if="a.by" class="text-xs text-slate-400">by {{ a.by }}</div>
                    </li>
                    <li v-if="!d.recent_activity?.length" class="px-5 py-6 text-center text-sm text-slate-400">No activity yet.</li>
                </ul>
            </section>
            <section class="card flex min-h-72 flex-col overflow-hidden lg:min-h-0">
                <h2 class="card-title shrink-0">Data objects</h2>
                <ul class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <li v-for="o in d.objects" :key="o.id" class="flex items-center justify-between px-5 py-3 text-sm"><Link :href="`/objects/${o.id}`" class="font-medium hover:text-brand-600">{{ o.name }}</Link><span class="badge">{{ o.records_count.toLocaleString() }} records</span></li>
                    <li v-if="!d.objects.length" class="px-5 py-6 text-center text-sm text-slate-400">No data objects yet.</li>
                </ul>
            </section>
        </div>
    </template>

    <Modal v-if="modal?.type === 'widget'" :title="draft.id ? 'Edit widget' : 'Add widget'" @close="modal = null">
        <form id="wf" class="space-y-3" @submit.prevent="saveWidget">
            <div><label class="label">Title</label><input v-model="draft.name" class="input" required /></div>
            <div><label class="label">Saved query</label>
                <select v-model="draft.saved_query_id" class="input" required><option v-for="q in queries" :key="q.id" :value="q.id">{{ q.name }}</option></select>
                <p v-if="!queries.length" class="mt-1 text-xs text-slate-400">No saved queries yet — build one in Company settings → <Link v-if="isAdmin" href="/admin/queries" class="font-medium text-brand-700 underline hover:text-brand-800">Query builder</Link><template v-else>Query builder</template>.</p></div>
            <label v-if="isAdmin && !draft.id" class="flex items-center gap-2 text-sm"><input v-model="draft.shared" type="checkbox" class="rounded" /> Show to everyone</label>
        </form>
        <template #footer><button class="btn-ghost" @click="modal = null">Cancel</button><button class="btn-primary" form="wf">Save</button></template>
    </Modal>

    <!-- a task from the list: its contact, on Notes & tasks, with the task open -->
    <Modal v-if="openTask?.contactId" :title="`${openTask.task.contact?.first_name ?? ''} ${openTask.task.contact?.last_name ?? ''}`.trim() || 'Contact'" width="max-w-6xl" @close="closeTask">
        <ContactShow :contact-id="openTask.contactId" embedded initial-tab="notes" :focus-task="openTask.task.id" @deleted="closeTask" />
    </Modal>
    <TaskDetail v-else-if="openTask" :task="openTask.task" @close="closeTask" @edit="router.visit('/tasks')" @toggle="toggleLoose" @delete="removeLoose" />

    <!-- every result of a widget: a table with search, sorting, pages and actions -->
    <Modal v-if="viewing" :title="viewing.name" width="max-w-6xl" @close="viewing = null">
        <WidgetResults :widget-id="viewing.id" :name="viewing.name" />
    </Modal>

    <!-- e-mail to everybody a widget finds: the same form as e-mailing one contact (templates, merge fields, formatting, attachments) -->
    <EmailComposer v-if="modal?.type === 'email'" :bulk="{ name: modal.widget.name, count: data[modal.widget.id]?.total }" @close="modal = null" @submit="sendBatch" />
    <Modal v-if="modal?.type === 'sms'" :title="`Text everyone in “${modal.widget.name}”`" @close="modal = null">
        <form id="bf" class="space-y-3" @submit.prevent="sendBatch()">
            <div><div class="mb-1 flex items-center justify-between"><label class="label !mb-0" for="batch-sms">Message</label><MergeFieldMenu @pick="addSmsField" /></div>
                <textarea id="batch-sms" ref="smsEl" v-model="draft.body" rows="5" class="input" required maxlength="1600" />
                <p class="mt-1 text-xs text-slate-400">Merge fields are filled in per contact when it is sent.</p></div>
        </form>
        <template #footer><button class="btn-ghost" @click="modal = null">Cancel</button><button class="btn-primary" form="bf">Send…</button></template>
    </Modal>
</template>
