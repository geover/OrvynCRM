<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Eye, Paperclip, Pencil, Plus, Search, StickyNote, Trash2, X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import TaskDetail from '@/Components/TaskDetail.vue';
import TaskEditor from '@/Components/TaskEditor.vue';
import TablePagination from '@/Components/TablePagination.vue';
import ContactShow from '../Contacts/Show.vue';
import { api } from '@/lib/api';
import { useStored } from '@/lib/stored';
import { confirmDialog, fmtDate, fmtDue, run } from '@/lib/ui';

// My tasks: what is open, overdue, due today or done, searchable and sortable, in numbered pages. A task opens in full from its title (or the eye),
// can be edited or deleted from its row, and is completed with its checkbox.
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
const loading = ref(true);
const title = ref('');
const input = ref(null);
const viewing = ref(null);          // a task without a contact, open in full
const inContact = ref(null);        // a task with a contact: its contact opens in a wide pop-up, on Notes & tasks, with the task open
const open = (t) => { if (t.contact_id) inContact.value = t; else viewing.value = t; };
const editing = ref(null);          // {task} the editor is open on (task: null for a new one)

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
const fromStart = () => { page.value = 1; load(); };
const goTo = (n) => { page.value = n; load(); window.scrollTo({ top: 0 }); };
const resize = (n) => { perPage.value = n; goTo(1); };
let timer;
watch(q, () => { clearTimeout(timer); timer = setTimeout(fromStart, 300); });
watch([status, whose, sort, priority], fromStart);
onMounted(() => { load(); if (new URLSearchParams(location.search).get('new')) input.value?.focus(); });

async function add() {
    if (!title.value.trim()) return;
    await api.post('/tasks', { title: title.value });
    title.value = '';
    load();
}
async function toggle(t) {
    await run(() => api.patch(`/tasks/${t.id}`, { completed: !t.completed_at }), t.completed_at ? undefined : t.contact_id ? 'Task completed, and noted on the contact' : 'Task completed');
    load();
}
async function remove(t) {
    if (!(await confirmDialog({ title: `Delete “${t.title}”?`, message: 'The task and its attachments are removed.', danger: true, confirmLabel: 'Delete' }))) return;
    if (await run(() => api.delete(`/tasks/${t.id}`), 'Task deleted')) { viewing.value = null; load(); }
}
const saved = () => { editing.value = null; load(); };
const edit = (t) => { viewing.value = null; editing.value = { task: t }; };
const overdue = (t) => t.due_at && !t.completed_at && new Date(t.due_at) < new Date();
const filtered = computed(() => !!(q.value || priority.value || whose.value !== 'mine'));
const clear = () => { q.value = ''; priority.value = ''; whose.value = 'mine'; };
</script>

<template>
    <Head title="Tasks" />
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="page-title">My tasks</h1><p class="text-sm text-slate-500">{{ meta ? `${meta.total.toLocaleString()} ${meta.total === 1 ? 'task' : 'tasks'}` : '' }}</p></div>
        <div class="flex flex-wrap items-center gap-2">
            <select v-model="status" class="input !w-auto min-w-44" aria-label="Show tasks that are">
                <option v-for="[v, l] in STATUSES" :key="v" :value="v">{{ l }}</option>
            </select>
            <button class="btn-primary" data-testid="task-new" @click="editing = { task: null }"><Plus class="size-4" /> New task</button>
        </div>
    </div>

    <form class="card mb-3 flex gap-2 p-3" @submit.prevent="add"><input ref="input" v-model="title" class="input" placeholder="Add a task and press Enter…" /><button class="btn-primary">Add</button></form>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-64 !pl-9" placeholder="Search tasks and contacts…" aria-label="Search tasks" data-testid="task-search" /></div>
        <select v-model="whose" class="input !w-auto min-w-44" aria-label="Whose tasks"><option value="mine">My tasks</option><option value="everyone">Everyone's tasks</option></select>
        <select v-model="priority" class="input !w-auto min-w-40" aria-label="Priority"><option value="">Any priority</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select>
        <select v-model="sort" class="input !w-auto min-w-40" aria-label="Sort by"><option v-for="[v, l] in SORTS" :key="v" :value="v">{{ l }}</option></select>
        <button v-if="filtered" type="button" class="btn-ghost" @click="clear"><X class="size-4" /> Clear filters</button>
    </div>

    <div class="card divide-y divide-slate-100" :class="loading && 'opacity-60'">
        <div v-for="t in tasks" :key="t.id" class="flex items-center gap-3 px-5 py-3 text-sm" data-testid="task-row">
            <input type="checkbox" :checked="!!t.completed_at" class="size-4 shrink-0 rounded" :aria-label="t.completed_at ? 'Mark open' : 'Complete'" @change="toggle(t)" />
            <div class="min-w-0 flex-1">
                <button type="button" :class="t.completed_at && 'text-slate-400 line-through'" class="block max-w-full truncate text-left font-medium hover:text-brand-700 hover:underline" data-testid="task-open" @click="open(t)">{{ t.title }}</button>
                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-400">
                    <Link v-if="t.contact" :href="`/contacts/${t.contact_id}`" class="text-brand-600 hover:underline">{{ t.contact.first_name }} {{ t.contact.last_name }}</Link>
                    <span v-if="t.priority === 'high'" class="font-medium text-red-600">High priority</span>
                    <span v-if="t.note" class="inline-flex items-center gap-1" :title="`About note: ${t.note.title || 'Note'}`"><StickyNote class="size-3" /> {{ t.note.title || 'Note' }}</span>
                    <span v-if="t.attachments?.length" class="inline-flex items-center gap-1"><Paperclip class="size-3" /> {{ t.attachments.length }}</span>
                    <span v-if="whose === 'everyone' && t.assignee">{{ t.assignee.name }}</span>
                </div>
            </div>
            <span v-if="t.due_at" :title="fmtDate(t.due_at, true)" :class="overdue(t) ? 'bg-red-50 text-red-600' : ''" class="badge shrink-0">{{ fmtDue(t.due_at) }}</span>
            <div class="flex shrink-0 gap-0.5">
                <button class="btn-ghost !px-1.5" title="View" aria-label="View in full" data-testid="task-view" @click="open(t)"><Eye class="size-4" /></button>
                <button class="btn-ghost !px-1.5" title="Edit" aria-label="Edit task" data-testid="task-edit" @click="edit(t)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete task" data-testid="task-delete" @click="remove(t)"><Trash2 class="size-4" /></button>
            </div>
        </div>
        <div v-if="!tasks.length && !loading" class="px-5 py-10 text-center text-slate-400">Nothing here.</div>
    </div>
    <div v-if="meta?.total" class="card mt-3"><TablePagination :meta="meta" :loading="loading" @page="goTo" @size="resize" /></div>

    <Modal v-if="inContact" :title="`${inContact.contact?.first_name ?? ''} ${inContact.contact?.last_name ?? ''}`.trim() || 'Contact'" width="max-w-6xl" @close="inContact = null; load()">
        <ContactShow :contact-id="inContact.contact_id" embedded initial-tab="notes" :focus-task="inContact.id" @deleted="inContact = null; load()" />
    </Modal>
    <TaskDetail v-if="viewing" :task="viewing" @close="viewing = null" @edit="edit" @toggle="toggle" @delete="remove" />
    <TaskEditor v-if="editing" :task="editing.task" @close="editing = null" @saved="saved" />
</template>
