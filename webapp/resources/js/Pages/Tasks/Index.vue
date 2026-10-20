<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { AlarmClock, CheckCircle2, Eye, Flame, LayoutGrid, ListTodo, Paperclip, Pencil, Plus, Rows3, Search, StickyNote, Trash2, X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import StatTile from '@/Components/StatTile.vue';
import TaskDetail from '@/Components/TaskDetail.vue';
import TaskEditor from '@/Components/TaskEditor.vue';
import TablePagination from '@/Components/TablePagination.vue';
import ContactShow from '../Contacts/Show.vue';
import { api } from '@/lib/api';
import { toText } from '@/lib/richtext';
import { useStored } from '@/lib/stored';
import { confirmDialog, fmtDate, fmtDue, run } from '@/lib/ui';

// My tasks: how the day looks (open, due today, overdue, done), then the tasks themselves as a list or as cards, searchable and sortable, in numbered pages.
// A task opens in full from its title (or the eye), can be edited or deleted from its row, and is completed with its checkbox.
const STATUSES = [['open', 'Open'], ['today', 'Due today'], ['overdue', 'Overdue'], ['done', 'Completed'], ['all', 'All']];
const SORTS = [['due_at', 'Due date'], ['-created_at', 'Newest'], ['-priority', 'Priority'], ['title', 'Title']];

const asked = new URLSearchParams(location.search).get('status');
const status = ref(STATUSES.some(([v]) => v === asked) ? asked : 'open');       // the dashboard tiles open it on Open or Overdue
const whose = ref('mine');                                                       // mine | everyone (what the person is allowed to see)
const sort = ref('due_at');
const q = ref('');
const priority = ref('');
const tasks = ref([]);
const meta = ref(null);
const page = ref(1);
const perPage = useStored('perpage:tasks', 25);
const display = useStored('display:tasks', 'list');                              // list | cards
const loading = ref(true);
const title = ref('');
const quickDue = ref('');                                                        // '' | today | tomorrow: when a quickly added task is due
const input = ref(null);
const viewing = ref(null);          // a task without a contact, open in full
const inContact = ref(null);        // a task with a contact: its contact opens in a wide pop-up, on Notes & tasks, with the task open
const open = (t) => { if (t.contact_id) inContact.value = t; else viewing.value = t; };
const editing = ref(null);          // {task} the editor is open on (task: null for a new one)
const summary = ref(null);
const me = usePage().props.auth.user;

async function loadSummary() {
    try { summary.value = (await api.get('/tasks/summary', { mine: whose.value === 'mine' ? 1 : undefined })).data; } catch { summary.value = null; }
}
async function load() {
    loading.value = true;
    try {
        const res = await api.get('/tasks', {
            'filter[mine]': whose.value === 'mine' ? 1 : undefined, 'filter[status]': status.value, 'filter[priority]': priority.value || undefined, 'filter[q]': q.value || undefined,
            sort: sort.value, page: page.value, per_page: perPage.value,
        });
        if (res.meta?.last_page && page.value > res.meta.last_page) { page.value = res.meta.last_page; return load(); }
        tasks.value = res.data;
        meta.value = res.meta;
        if (viewing.value) viewing.value = tasks.value.find((t) => t.id === viewing.value.id) ?? null;     // it changed, or left this list
    } finally { loading.value = false; }
}
const reload = () => { load(); loadSummary(); };
const fromStart = () => { page.value = 1; load(); };
const goTo = (n) => { page.value = n; load(); window.scrollTo({ top: 0 }); };
const resize = (n) => { perPage.value = n; goTo(1); };
let timer;
watch(q, () => { clearTimeout(timer); timer = setTimeout(fromStart, 300); });
watch([status, whose, sort, priority], fromStart);
watch(whose, loadSummary);
onMounted(() => { load(); loadSummary(); if (new URLSearchParams(location.search).get('new')) input.value?.focus(); });

const pad = (n) => String(n).padStart(2, '0');
function dueFor(kind) {                                                          // the end of today or tomorrow's working day, as the editor writes dates
    if (!kind) return undefined;
    const d = new Date(); if (kind === 'tomorrow') d.setDate(d.getDate() + 1);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T17:00`;
}
async function add() {
    if (!title.value.trim()) return;
    await api.post('/tasks', { title: title.value, due_at: dueFor(quickDue.value) });
    title.value = ''; quickDue.value = '';
    reload();
}
async function toggle(t) {
    await run(() => api.patch(`/tasks/${t.id}`, { completed: !t.completed_at }), t.completed_at ? undefined : t.contact_id ? 'Task completed, and noted on the contact' : 'Task completed');
    reload();
}
async function remove(t) {
    if (!(await confirmDialog({ title: `Delete “${t.title}”?`, message: 'The task and its attachments are removed.', danger: true, confirmLabel: 'Delete' }))) return;
    if (await run(() => api.delete(`/tasks/${t.id}`), 'Task deleted')) { viewing.value = null; reload(); }
}
const saved = () => { editing.value = null; reload(); };
const edit = (t) => { viewing.value = null; editing.value = { task: t }; };
const overdue = (t) => t.due_at && !t.completed_at && new Date(t.due_at) < new Date();
const filtered = computed(() => !!(q.value || priority.value || whose.value !== 'mine'));
const clear = () => { q.value = ''; priority.value = ''; whose.value = 'mine'; };

// ---- the look of a task
const isToday = (d) => d.toDateString() === new Date().toDateString();
const dueTone = (t) => {
    if (t.completed_at) return 'bg-emerald-50 text-emerald-700';
    if (!t.due_at) return '';
    const d = new Date(t.due_at);
    return d < new Date() ? 'bg-red-50 text-red-600' : isToday(d) ? 'bg-amber-50 text-amber-700' : 'bg-sky-50 text-sky-700';
};
const stripe = (t) => (t.completed_at ? '#10b981' : t.priority === 'high' ? '#ef4444' : t.priority === 'low' ? '#7dd3fc' : '#cbd5e1');
const snippet = (t) => toText(t.description);
const initials = (c) => `${c?.first_name?.[0] ?? ''}${c?.last_name?.[0] ?? ''}`.toUpperCase() || '?';
const todayDone = computed(() => (summary.value ? summary.value.done_today : 0));
const todayAll = computed(() => (summary.value ? summary.value.today + summary.value.done_today : 0));
const greeting = computed(() => {
    const s = summary.value;
    if (!s) return '';
    if (!s.open) return whose.value === 'mine' ? 'Nothing open. Enjoy the quiet, or add something above.' : 'Nothing open for anybody.';
    const bits = [s.today ? `${s.today} due today` : null, s.overdue ? `${s.overdue} overdue` : null].filter(Boolean);
    return `${s.open} open${bits.length ? `: ${bits.join(', ')}` : ''}.`;
});
const showStatus = (v) => { status.value = v; priority.value = ''; };
const showHigh = () => { status.value = 'open'; priority.value = 'high'; };
</script>

<template>
    <Head title="Tasks" />
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="page-title">{{ whose === 'mine' ? `Hi ${me.name.split(' ')[0]}` : 'Everyone\'s tasks' }}</h1>
            <p class="text-sm text-slate-500" data-testid="tasks-greeting">{{ greeting || (meta ? `${meta.total.toLocaleString()} ${meta.total === 1 ? 'task' : 'tasks'}` : '') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <select v-model="status" class="input !w-auto min-w-44" aria-label="Show tasks that are">
                <option v-for="[v, l] in STATUSES" :key="v" :value="v">{{ l }}</option>
            </select>
            <button class="btn-primary" data-testid="task-new" @click="editing = { task: null }"><Plus class="size-4" /> New task</button>
        </div>
    </div>

    <section v-if="summary" class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5" data-testid="tasks-summary" aria-label="Tasks in numbers">
        <StatTile label="Open" :value="summary.open.toLocaleString()" hint="still to do" action="Show open" @click="showStatus('open')" />
        <StatTile label="Due today" :value="summary.today.toLocaleString()" action="Show today" @click="showStatus('today')">
            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="todayDone" :aria-valuemin="0" :aria-valuemax="todayAll" :aria-label="`${todayDone} of ${todayAll} done today`"><div class="h-full rounded-full bg-emerald-500 transition-[width] duration-500" :style="{ width: `${todayAll ? (todayDone / todayAll) * 100 : 0}%` }" /></div>
            <div class="mt-1 text-xs text-slate-400">{{ todayAll ? `${todayDone} of ${todayAll} done` : 'a clear day' }}</div>
        </StatTile>
        <StatTile label="Overdue" :value="summary.overdue.toLocaleString()" :danger="summary.overdue > 0" :hint="summary.overdue ? 'past their date' : 'none, well done'" :action="summary.overdue ? 'Show overdue' : ''" @click="showStatus('overdue')" />
        <StatTile label="Done, last 7 days" :value="summary.done_7d.toLocaleString()" hint="keep it going" action="Show completed" @click="showStatus('done')" />
        <StatTile label="High priority" :value="summary.high.toLocaleString()" hint="open and urgent" :action="summary.high ? 'Show them' : ''" @click="showHigh" />
    </section>

    <form class="card mb-3 flex flex-wrap items-center gap-2 p-3" @submit.prevent="add">
        <input ref="input" v-model="title" class="input min-w-48 flex-1" placeholder="Add a task and press Enter…" aria-label="New task" />
        <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs" role="group" aria-label="When is it due">
            <button v-for="[v, l] in [['today', 'Today'], ['tomorrow', 'Tomorrow']]" :key="v" type="button" class="rounded-md px-2.5 py-1.5 font-medium" :class="quickDue === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :aria-pressed="quickDue === v" @click="quickDue = quickDue === v ? '' : v">{{ l }}</button>
        </div>
        <button class="btn-primary">Add</button>
    </form>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-64 !pl-9" placeholder="Search tasks and contacts…" aria-label="Search tasks" data-testid="task-search" /></div>
        <select v-model="whose" class="input !w-auto min-w-44" aria-label="Whose tasks"><option value="mine">My tasks</option><option value="everyone">Everyone's tasks</option></select>
        <select v-model="priority" class="input !w-auto min-w-40" aria-label="Priority"><option value="">Any priority</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select>
        <select v-model="sort" class="input !w-auto min-w-40" aria-label="Sort by"><option v-for="[v, l] in SORTS" :key="v" :value="v">{{ l }}</option></select>
        <button v-if="filtered" type="button" class="btn-ghost" @click="clear"><X class="size-4" /> Clear filters</button>
        <div class="ml-auto inline-flex rounded-lg border border-slate-200 p-0.5" role="group" aria-label="Show tasks as">
            <button type="button" class="rounded-md p-1.5" :class="display === 'list' ? 'bg-brand-50 text-brand-700' : 'text-slate-400 hover:text-slate-700'" title="List" aria-label="List" :aria-pressed="display === 'list'" data-testid="view-list" @click="display = 'list'"><Rows3 class="size-4" /></button>
            <button type="button" class="rounded-md p-1.5" :class="display === 'cards' ? 'bg-brand-50 text-brand-700' : 'text-slate-400 hover:text-slate-700'" title="Cards" aria-label="Cards" :aria-pressed="display === 'cards'" data-testid="view-cards" @click="display = 'cards'"><LayoutGrid class="size-4" /></button>
        </div>
    </div>

    <!-- as a list -->
    <div v-if="display === 'list'" class="card divide-y divide-slate-100 overflow-hidden" :class="loading && 'opacity-60'">
        <div v-for="t in tasks" :key="t.id" class="relative flex items-start gap-3 py-3.5 pl-6 pr-5 text-sm transition hover:bg-slate-50/70" data-testid="task-row">
            <span class="absolute inset-y-0 left-0 w-1.5" :style="{ background: stripe(t) }" aria-hidden="true" />
            <input type="checkbox" :checked="!!t.completed_at" class="mt-0.5 size-5 shrink-0 rounded-full" :aria-label="t.completed_at ? 'Mark open' : 'Complete'" @change="toggle(t)" />
            <div class="min-w-0 flex-1">
                <button type="button" :class="t.completed_at && 'text-slate-400 line-through'" class="block max-w-full truncate text-left font-medium hover:text-brand-700 hover:underline" data-testid="task-open" @click="open(t)">{{ t.title }}</button>
                <p v-if="snippet(t)" class="mt-0.5 truncate text-xs text-slate-500" data-testid="task-snippet">{{ snippet(t) }}</p>
                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                    <Link v-if="t.contact" :href="`/contacts/${t.contact_id}`" class="inline-flex items-center gap-1.5 text-brand-600 hover:underline"><span class="grid size-4 place-items-center rounded-full bg-brand-100 text-[8px] font-semibold text-brand-700">{{ initials(t.contact) }}</span>{{ t.contact.first_name }} {{ t.contact.last_name }}</Link>
                    <span v-if="t.priority === 'high'" class="inline-flex items-center gap-1 font-medium text-red-600"><Flame class="size-3" /> High priority</span>
                    <span v-if="t.note" class="inline-flex items-center gap-1" :title="`About note: ${t.note.title || 'Note'}`"><StickyNote class="size-3" /> {{ t.note.title || 'Note' }}</span>
                    <span v-if="t.attachments?.length" class="inline-flex items-center gap-1"><Paperclip class="size-3" /> {{ t.attachments.length }}</span>
                    <span v-if="whose === 'everyone' && t.assignee" class="inline-flex items-center gap-1"><span class="grid size-4 place-items-center rounded-full bg-slate-100 text-[8px] font-semibold text-slate-500">{{ t.assignee.name[0] }}</span>{{ t.assignee.name }}</span>
                </div>
            </div>
            <span v-if="t.due_at" :title="fmtDate(t.due_at, true)" :class="dueTone(t)" class="badge shrink-0"><AlarmClock class="mr-1 size-3" />{{ fmtDue(t.due_at) }}</span>
            <div class="flex shrink-0 gap-0.5">
                <button class="btn-ghost !px-1.5" title="View" aria-label="View in full" data-testid="task-view" @click="open(t)"><Eye class="size-4" /></button>
                <button class="btn-ghost !px-1.5" title="Edit" aria-label="Edit task" data-testid="task-edit" @click="edit(t)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete task" data-testid="task-delete" @click="remove(t)"><Trash2 class="size-4" /></button>
            </div>
        </div>
        <div v-if="!tasks.length && !loading" class="px-5 py-12 text-center text-slate-400"><CheckCircle2 class="mx-auto mb-2 size-8 text-slate-300" />Nothing here.</div>
    </div>

    <!-- as cards -->
    <div v-else :class="loading && 'opacity-60'">
        <div v-if="tasks.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="t in tasks" :key="t.id" class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:shadow-md" data-testid="task-row">
                <div class="h-1.5" :style="{ background: stripe(t) }" />
                <div class="flex items-start gap-3 p-4 pb-2">
                    <input type="checkbox" :checked="!!t.completed_at" class="mt-0.5 size-5 shrink-0 rounded-full" :aria-label="t.completed_at ? 'Mark open' : 'Complete'" @change="toggle(t)" />
                    <button type="button" :class="t.completed_at && 'text-slate-400 line-through'" class="min-w-0 flex-1 text-left font-semibold leading-snug hover:text-brand-700" data-testid="task-open" @click="open(t)">{{ t.title }}</button>
                </div>
                <p v-if="snippet(t)" class="line-clamp-3 px-4 pb-2 text-sm text-slate-500" data-testid="task-snippet">{{ snippet(t) }}</p>
                <div class="flex flex-wrap items-center gap-1.5 px-4 pb-3 text-xs">
                    <span v-if="t.due_at" :title="fmtDate(t.due_at, true)" :class="dueTone(t)" class="badge"><AlarmClock class="mr-1 size-3" />{{ fmtDue(t.due_at) }}</span>
                    <span v-if="t.priority === 'high'" class="badge !bg-red-50 !text-red-600"><Flame class="mr-1 size-3" />High</span>
                    <span v-if="t.note" class="badge !bg-amber-50 !text-amber-700" :title="`About note: ${t.note.title || 'Note'}`"><StickyNote class="mr-1 size-3" />{{ t.note.title || 'Note' }}</span>
                    <span v-if="t.attachments?.length" class="badge"><Paperclip class="mr-1 size-3" />{{ t.attachments.length }}</span>
                </div>
                <div class="mt-auto flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-400">
                    <span class="flex min-w-0 items-center gap-1.5">
                        <Link v-if="t.contact" :href="`/contacts/${t.contact_id}`" class="inline-flex min-w-0 items-center gap-1.5 text-brand-600 hover:underline"><span class="grid size-5 shrink-0 place-items-center rounded-full bg-brand-100 text-[9px] font-semibold text-brand-700">{{ initials(t.contact) }}</span><span class="truncate">{{ t.contact.first_name }} {{ t.contact.last_name }}</span></Link>
                        <span v-else-if="whose === 'everyone' && t.assignee" class="truncate">{{ t.assignee.name }}</span>
                        <span v-else class="flex items-center gap-1"><ListTodo class="size-3.5" /> No contact</span>
                    </span>
                    <span class="inline-flex shrink-0 gap-0.5 opacity-60 transition group-hover:opacity-100">
                        <button class="btn-ghost !px-1.5 !py-1" title="View" aria-label="View in full" data-testid="task-view" @click="open(t)"><Eye class="size-4" /></button>
                        <button class="btn-ghost !px-1.5 !py-1" title="Edit" aria-label="Edit task" data-testid="task-edit" @click="edit(t)"><Pencil class="size-4" /></button>
                        <button class="btn-ghost !px-1.5 !py-1 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete task" data-testid="task-delete" @click="remove(t)"><Trash2 class="size-4" /></button>
                    </span>
                </div>
            </article>
        </div>
        <div v-else-if="!loading" class="card px-5 py-12 text-center text-slate-400"><CheckCircle2 class="mx-auto mb-2 size-8 text-slate-300" />Nothing here.</div>
    </div>
    <div v-if="meta?.total" class="card mt-3"><TablePagination :meta="meta" :loading="loading" @page="goTo" @size="resize" /></div>

    <Modal v-if="inContact" :title="`${inContact.contact?.first_name ?? ''} ${inContact.contact?.last_name ?? ''}`.trim() || 'Contact'" width="max-w-6xl" @close="inContact = null; reload()">
        <ContactShow :contact-id="inContact.contact_id" embedded initial-tab="notes" :focus-task="inContact.id" @deleted="inContact = null; reload()" />
    </Modal>
    <TaskDetail v-if="viewing" :task="viewing" @close="viewing = null" @edit="edit" @toggle="toggle" @delete="remove" />
    <TaskEditor v-if="editing" :task="editing.task" @close="editing = null" @saved="saved" />
</template>
