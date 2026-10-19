<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { CheckCircle2, Circle, Eye, FileText, Link2, ListPlus, ListTodo, Pencil, Plus, StickyNote, Trash2, X } from 'lucide-vue-next';
import AttachmentPicker from '@/Components/AttachmentPicker.vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const props = defineProps({ contactId: Number, focusTask: { type: Number, default: null } });       // focusTask: a task to open in full at once (from the dashboard)
const emit = defineEmits(['changed']);

const notes = ref([]);
const tasks = ref([]);
const users = ref([]);
const knownTags = ref([]);
const loading = ref(true);
const nextNotes = ref(null);     // cursor of the next page of notes
const nextTasks = ref(null);     // cursor of the next page of tasks
const filter = ref(props.focusTask ? 'tasks' : 'all');       // all | notes | tasks | open (opened from a task: the Tasks filter is on)
const q = ref('');
const modal = ref(null);         // 'note' | 'task' | null
const editing = ref(null);
const draft = ref({});
const errors = ref({});

async function load() {
    const [n, t] = await Promise.all([api.get(`/contacts/${props.contactId}/notes`), api.get('/tasks', { 'filter[contact_id]': props.contactId, per_page: 100 })]);
    notes.value = n.data; nextNotes.value = n.next_cursor ?? null; tasks.value = t.data; nextTasks.value = t.next_cursor ?? null; loading.value = false;
    if (props.focusTask && !focused && tasks.value.some((x) => x.id === props.focusTask)) { focused = true; viewing.value = { kind: 'task', id: props.focusTask }; }
}
let focused = false;
async function moreNotes() {
    const n = await api.get(`/contacts/${props.contactId}/notes`, { cursor: nextNotes.value });
    notes.value = [...notes.value, ...n.data]; nextNotes.value = n.next_cursor ?? null;
}
async function moreTasks() {
    const t = await api.get('/tasks', { 'filter[contact_id]': props.contactId, per_page: 100, cursor: nextTasks.value });
    tasks.value = [...tasks.value, ...t.data]; nextTasks.value = t.next_cursor ?? null;
}
onMounted(async () => {
    load();
    users.value = (await api.get('/users')).data;
    knownTags.value = (await api.get('/note-tags')).data;
});

const allItems = computed(() => [
        ...notes.value.map((n) => ({ kind: 'note', id: n.id, title: n.title, body: n.body, tags: n.tags ?? [], by: n.author?.name, at: n.created_at, attachments: n.attachments ?? [], task: n.task, raw: n })),
        ...tasks.value.map((t) => ({ kind: 'task', id: t.id, title: t.title, body: t.description, tags: [], by: t.assignee?.name, at: t.due_at ?? t.created_at, done: !!t.completed_at, due: t.due_at, priority: t.priority, attachments: t.attachments ?? [], note: t.note, raw: t })),
]);
const items = computed(() => {
    const term = q.value.trim().toLowerCase();
    return allItems.value
        .filter((i) => (filter.value === 'all' ? true : filter.value === 'notes' ? i.kind === 'note' : filter.value === 'tasks' ? i.kind === 'task' : i.kind === 'task' && !i.done))
        .filter((i) => !term || [i.title, i.body, ...(i.tags ?? [])].some((s) => (s ?? '').toLowerCase().includes(term)))
        .sort((a, b) => new Date(b.at) - new Date(a.at));
});

// ---- the whole note or task, read in a pop-up (the list shows the first lines of each)
const viewing = ref(null);                                   // {kind, id}
const viewed = computed(() => (viewing.value ? allItems.value.find((i) => i.kind === viewing.value.kind && i.id === viewing.value.id) ?? null : null));
watch(viewed, (v) => { if (viewing.value && !v && !loading.value) viewing.value = null; });        // it was deleted
// when the task that was opened from the dashboard or My tasks is closed again, it is the one lit up in the list
watch(viewing, async (v, was) => {
    if (v || !was || was.kind !== 'task' || was.id !== props.focusTask) return;
    await nextTick();
    const el = document.getElementById(`nt-task-${was.id}`);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('ring-2', 'ring-brand-500');
    setTimeout(() => el.classList.remove('ring-2', 'ring-brand-500'), 2200);
});
const view = (i) => { viewing.value = { kind: i.kind, id: i.id }; };
const fromView = (fn) => { const i = viewed.value; viewing.value = null; if (i) fn(i); };
const priorityLabel = { low: 'Low', normal: 'Normal', high: 'High' };

const freshPending = () => ({ files: [], links: [], removed: [] });
function openNew(kind, fromNote = null) {
    editing.value = null; errors.value = {};
    draft.value = kind === 'note' ? { title: '', body: '', tags: [], tagInput: '' }
        : { title: fromNote ? `Follow up: ${(fromNote.title || fromNote.body || '').split('\n')[0].slice(0, 80)}` : '', description: '', assigned_to: '', due_at: '', priority: 'normal', note_id: fromNote?.id ?? '' };
    pending.value = freshPending();
    modal.value = kind;
}
const pending = ref(freshPending());
function openEdit(i) {
    editing.value = i; errors.value = {};
    draft.value = i.kind === 'note' ? { title: i.raw.title ?? '', body: i.raw.body, tags: [...(i.raw.tags ?? [])], tagInput: '' }
        : { title: i.raw.title, description: i.raw.description ?? '', assigned_to: i.raw.assigned_to ?? '', due_at: i.raw.due_at ? i.raw.due_at.slice(0, 16) : '', priority: i.raw.priority, note_id: i.raw.note_id ?? '' };
    pending.value = freshPending();
    modal.value = i.kind;
}
const addTag = () => {
    const t = draft.value.tagInput.trim();
    if (t && !draft.value.tags.includes(t)) draft.value.tags.push(t);
    draft.value.tagInput = '';
};

async function save() {
    errors.value = {};
    try {
        if (modal.value === 'note') {
            addTag();
            const body = { title: draft.value.title || null, body: draft.value.body, tags: draft.value.tags };
            const saved = editing.value ? await api.patch(`/contacts/${props.contactId}/notes/${editing.value.id}`, body) : await api.post(`/contacts/${props.contactId}/notes`, body);
            await syncAttachments(`/notes/${saved.data.id}`);
        } else {
            const body = { ...draft.value, assigned_to: draft.value.assigned_to || null, due_at: draft.value.due_at || null, note_id: draft.value.note_id || null, ...(editing.value ? {} : { contact_id: props.contactId }) };
            const saved = editing.value ? await api.patch(`/tasks/${editing.value.id}`, body) : await api.post('/tasks', body);
            await syncAttachments(`/tasks/${saved.data.id}`);
        }
        modal.value = null; await load(); emit('changed');
    } catch (e) { errors.value = e.errors ?? {}; if (!Object.keys(errors.value).length) throw e; }
}
// the files and links added in the form go up, and the ones taken away come off
async function syncAttachments(base) {
    const p = pending.value;
    for (const id of p.removed) await api.delete(`/attachments/${id}`);
    for (const file of p.files) { const fd = new FormData(); fd.append('file', file); await api.postForm(`${base}/attachments`, fd); }
    for (const l of p.links) await api.post(`${base}/attachments`, { url: l.url, title: l.title || null });
}
async function dropAttachment(a) {
    if (!(await confirmDialog({ title: `Remove “${a.title}”?`, danger: true, confirmLabel: 'Remove' }))) return;
    await run(() => api.delete(`/attachments/${a.id}`), 'Removed'); load(); emit('changed');
}
const fileSize = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round((b ?? 0) / 1024))} KB`);
const isImage = (a) => !a.url && (a.mime ?? '').startsWith('image/');
const href = (a) => a.url || `/files/attachments/${a.id}`;

// jump to the note or task another one points at
async function reveal(kind, id) {
    filter.value = 'all'; q.value = '';
    await nextTick();
    const el = document.getElementById(`nt-${kind}-${id}`);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('ring-2', 'ring-brand-500');
    setTimeout(() => el.classList.remove('ring-2', 'ring-brand-500'), 1800);
}

async function toggle(i) {
    await run(() => api.patch(`/tasks/${i.id}`, { completed: !i.done }), i.done ? undefined : 'Task completed, and noted on the contact');
    load(); emit('changed');
}
async function remove(i) {
    if (!(await confirmDialog({ title: `Delete this ${i.kind}?`, danger: true, confirmLabel: 'Delete' }))) return;
    await run(() => (i.kind === 'note' ? api.delete(`/contacts/${props.contactId}/notes/${i.id}`) : api.delete(`/tasks/${i.id}`)), 'Deleted');
    load(); emit('changed');
}
const overdue = (i) => i.kind === 'task' && !i.done && i.due && new Date(i.due) < new Date();
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg bg-slate-100 p-1 text-sm">
                <button v-for="[v, l] in [['all', 'All'], ['notes', 'Notes'], ['tasks', 'Tasks'], ['open', 'Open tasks']]" :key="v" :class="filter === v ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-3 py-1" @click="filter = v">{{ l }}</button>
            </div>
            <input v-model="q" class="input !w-56" placeholder="Search notes &amp; tasks…" />
            <div class="ml-auto flex gap-2">
                <button class="btn-outline" @click="openNew('task')"><ListTodo class="size-4" /> Add task</button>
                <button class="btn-primary" @click="openNew('note')"><Plus class="size-4" /> Add note</button>
            </div>
        </div>

        <div v-if="loading" class="text-sm text-slate-400">Loading…</div>
        <div v-else class="space-y-2">
            <div v-for="i in items" :id="`nt-${i.kind}-${i.id}`" :key="i.kind + i.id" class="card flex gap-3 p-4 text-sm transition" :data-testid="`nt-${i.kind}`">
                <button v-if="i.kind === 'task'" class="mt-0.5 shrink-0" :title="i.done ? 'Mark open' : 'Complete'" @click="toggle(i)">
                    <CheckCircle2 v-if="i.done" class="size-5 text-emerald-600" /><Circle v-else class="size-5 text-slate-300 hover:text-emerald-500" />
                </button>
                <StickyNote v-else class="mt-0.5 size-5 shrink-0 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" :class="i.done && 'text-slate-400 line-through'" class="text-left font-medium hover:text-brand-700 hover:underline" data-testid="nt-open" @click="view(i)">{{ i.title || (i.kind === 'note' ? 'Note' : 'Task') }}</button>
                        <span v-if="i.kind === 'task' && i.priority === 'high'" class="badge !bg-red-50 !text-red-600">high</span>
                        <span v-if="overdue(i)" class="badge !bg-red-50 !text-red-600">overdue</span>
                        <span v-for="t in i.tags" :key="t" class="badge !bg-amber-50 !text-amber-700">{{ t }}</span>
                    </div>
                    <p v-if="i.body" class="mt-1 line-clamp-3 whitespace-pre-wrap text-slate-600">{{ i.body }}</p>
                    <div v-if="i.kind === 'task' && i.note" class="mt-1.5"><button type="button" class="badge inline-flex items-center gap-1.5 !bg-amber-50 !text-amber-700 hover:!bg-amber-100" data-testid="nt-related-note" @click="reveal('note', i.note.id)"><StickyNote class="size-3" /> About note: {{ i.note.title || 'Note' }}</button></div>
                    <div v-if="i.kind === 'note' && i.task" class="mt-1.5"><button type="button" class="badge inline-flex items-center gap-1.5 !bg-emerald-50 !text-emerald-700 hover:!bg-emerald-100" data-testid="nt-related-task" @click="reveal('task', i.task.id)"><CheckCircle2 class="size-3" /> From task: {{ i.task.title }}</button></div>
                    <ul v-if="i.attachments.length" class="mt-2 flex flex-wrap gap-2" data-testid="nt-attachments">
                        <li v-for="a in i.attachments" :key="a.id" class="group/att relative">
                            <a :href="href(a)" :target="a.url ? '_blank' : undefined" rel="noopener noreferrer" class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-brand-700 hover:bg-slate-100" :title="a.url || a.title">
                                <img v-if="isImage(a)" :src="href(a)" :alt="a.title" class="size-8 rounded object-cover" loading="lazy" />
                                <component :is="a.url ? Link2 : FileText" v-else class="size-3.5 text-slate-400" /><span class="max-w-48 truncate">{{ a.title }}</span>
                            </a>
                            <button type="button" class="absolute -right-1.5 -top-1.5 hidden size-4 place-items-center rounded-full bg-white text-slate-400 shadow ring-1 ring-slate-200 hover:text-red-600 group-hover/att:grid max-md:grid" :aria-label="`Remove ${a.title}`" @click="dropAttachment(a)"><X class="size-3" /></button>
                        </li>
                    </ul>
                    <p class="mt-1.5 text-xs text-slate-400">
                        <template v-if="i.kind === 'task'">Due {{ i.due ? fmtDate(i.due, true) : 'no date' }} · </template>{{ i.by }} · {{ fmtDate(i.raw.created_at, true) }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-0.5">
                    <button class="btn-ghost !px-1.5" title="View" aria-label="View in full" data-testid="nt-view" @click="view(i)"><Eye class="size-4" /></button>
                    <button v-if="i.kind === 'note'" class="btn-ghost !px-1.5" title="Create a task from this note" aria-label="Create a task from this note" data-testid="nt-task-from-note" @click="openNew('task', i.raw)"><ListPlus class="size-4" /></button>
                    <button class="btn-ghost !px-1.5" title="Edit" @click="openEdit(i)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" @click="remove(i)"><Trash2 class="size-4" /></button>
                </div>
            </div>
            <div v-if="!items.length" class="card px-5 py-10 text-center text-sm text-slate-400">Nothing here yet.</div>
            <div v-if="nextNotes || nextTasks" class="flex justify-center gap-2">
                <button v-if="nextNotes" class="btn-outline" data-testid="nt-load-more-notes" @click="moreNotes">Load more notes</button>
                <button v-if="nextTasks" class="btn-outline" data-testid="nt-load-more-tasks" @click="moreTasks">Load more tasks</button>
            </div>
        </div>

        <Modal v-if="viewed" :title="viewed.title || (viewed.kind === 'note' ? 'Note' : 'Task')" width="max-w-2xl" @close="viewing = null">
            <div class="space-y-4 text-sm" data-testid="nt-detail">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge" :class="viewed.kind === 'note' ? '!bg-amber-50 !text-amber-700' : '!bg-brand-50 !text-brand-700'">{{ viewed.kind === 'note' ? 'Note' : 'Task' }}</span>
                    <template v-if="viewed.kind === 'task'">
                        <span class="badge" :class="viewed.done ? '!bg-emerald-50 !text-emerald-700' : overdue(viewed) ? '!bg-red-50 !text-red-600' : ''">{{ viewed.done ? 'Completed' : overdue(viewed) ? 'Overdue' : 'Open' }}</span>
                        <span v-if="viewed.priority === 'high'" class="badge !bg-red-50 !text-red-600">High priority</span>
                    </template>
                    <span v-for="t in viewed.tags" :key="t" class="badge !bg-amber-50 !text-amber-700">{{ t }}</span>
                </div>

                <dl class="grid gap-x-6 gap-y-2 text-slate-600 sm:grid-cols-2">
                    <template v-if="viewed.kind === 'task'">
                        <div><dt class="text-xs uppercase tracking-wide text-slate-400">Due</dt><dd>{{ viewed.due ? fmtDate(viewed.due, true) : 'No date' }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-slate-400">Assigned to</dt><dd>{{ viewed.by ?? 'Nobody' }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-slate-400">Priority</dt><dd>{{ priorityLabel[viewed.priority] ?? viewed.priority }}</dd></div>
                        <div v-if="viewed.raw.completed_at"><dt class="text-xs uppercase tracking-wide text-slate-400">Completed</dt><dd>{{ fmtDate(viewed.raw.completed_at, true) }}</dd></div>
                    </template>
                    <template v-else>
                        <div><dt class="text-xs uppercase tracking-wide text-slate-400">Written by</dt><dd>{{ viewed.by ?? 'Unknown' }}</dd></div>
                    </template>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Created</dt><dd>{{ fmtDate(viewed.raw.created_at, true) }}</dd></div>
                    <div v-if="viewed.raw.updated_at && viewed.raw.updated_at !== viewed.raw.created_at"><dt class="text-xs uppercase tracking-wide text-slate-400">Last changed</dt><dd>{{ fmtDate(viewed.raw.updated_at, true) }}</dd></div>
                </dl>

                <p v-if="viewed.body" class="whitespace-pre-wrap rounded-lg bg-slate-50 p-4 text-slate-700" data-testid="nt-detail-body">{{ viewed.body }}</p>
                <p v-else class="text-slate-400">{{ viewed.kind === 'note' ? 'No text.' : 'No description.' }}</p>

                <div v-if="viewed.kind === 'task' && viewed.note"><button type="button" class="badge inline-flex items-center gap-1.5 !bg-amber-50 !text-amber-700 hover:!bg-amber-100" @click="fromView(() => reveal('note', viewed.note.id))"><StickyNote class="size-3" /> About note: {{ viewed.note.title || 'Note' }}</button></div>
                <div v-if="viewed.kind === 'note' && viewed.task"><button type="button" class="badge inline-flex items-center gap-1.5 !bg-emerald-50 !text-emerald-700 hover:!bg-emerald-100" @click="fromView(() => reveal('task', viewed.task.id))"><CheckCircle2 class="size-3" /> From task: {{ viewed.task.title }}</button></div>

                <div v-if="viewed.attachments.length">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Attachments</h3>
                    <ul class="grid gap-2 sm:grid-cols-2">
                        <li v-for="a in viewed.attachments" :key="a.id" class="flex items-center gap-3 rounded-lg border border-slate-200 p-2">
                            <a :href="href(a)" :target="a.url ? '_blank' : undefined" rel="noopener noreferrer" class="flex min-w-0 flex-1 items-center gap-3 text-brand-700 hover:underline">
                                <img v-if="isImage(a)" :src="href(a)" :alt="a.title" class="size-14 shrink-0 rounded object-cover" loading="lazy" />
                                <span v-else class="grid size-14 shrink-0 place-items-center rounded bg-slate-100 text-slate-400"><component :is="a.url ? Link2 : FileText" class="size-6" /></span>
                                <span class="min-w-0"><span class="block truncate font-medium">{{ a.title }}</span><span class="block truncate text-xs text-slate-400">{{ a.url ? a.url : fileSize(a.size) }}</span></span>
                            </a>
                            <button type="button" class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" :aria-label="`Remove ${a.title}`" @click="dropAttachment(a)"><Trash2 class="size-4" /></button>
                        </li>
                    </ul>
                </div>
            </div>
            <template #footer>
                <button class="btn-ghost mr-auto !text-red-600 hover:!bg-red-50" @click="remove(viewed)"><Trash2 class="size-4" /> Delete</button>
                <button v-if="viewed.kind === 'note'" class="btn-outline" @click="fromView((i) => openNew('task', i.raw))"><ListPlus class="size-4" /> Create task</button>
                <button v-else class="btn-outline" data-testid="nt-detail-toggle" @click="toggle(viewed)"><CheckCircle2 class="size-4" /> {{ viewed.done ? 'Reopen' : 'Complete' }}</button>
                <button class="btn-primary" data-testid="nt-detail-edit" @click="fromView((i) => openEdit(i))"><Pencil class="size-4" /> Edit</button>
            </template>
        </Modal>

        <Modal v-if="modal" :title="`${editing ? 'Edit' : 'Add'} ${modal}`" @close="modal = null">
            <form id="ntform" class="space-y-3" @submit.prevent="save">
                <div><label class="label">Title</label><input v-model="draft.title" class="input" :required="modal === 'task'" /><p v-if="errors.title" class="mt-1 text-xs text-red-600">{{ errors.title[0] }}</p></div>
                <template v-if="modal === 'note'">
                    <div><label class="label">Note</label><textarea v-model="draft.body" rows="6" class="input" required /><p v-if="errors.body" class="mt-1 text-xs text-red-600">{{ errors.body[0] }}</p></div>
                    <div>
                        <label class="label">Tags</label>
                        <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-200 p-2">
                            <span v-for="t in draft.tags" :key="t" class="badge gap-1">{{ t }}<button type="button" @click="draft.tags.splice(draft.tags.indexOf(t), 1)"><X class="size-3" /></button></span>
                            <input v-model="draft.tagInput" list="note-tags" class="min-w-24 flex-1 border-0 bg-transparent p-1 text-sm outline-none" placeholder="Add tag + Enter" @keydown.enter.prevent="addTag" />
                            <datalist id="note-tags"><option v-for="t in knownTags" :key="t" :value="t" /></datalist>
                        </div>
                    </div>
                    <AttachmentPicker :existing="editing?.attachments ?? []" :pending="pending" />
                </template>
                <template v-else>
                    <div><label class="label">Description</label><textarea v-model="draft.description" rows="3" class="input" /></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label">Assign to</label><select v-model="draft.assigned_to" class="input"><option value="">Me</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                        <div><label class="label">Due</label><input v-model="draft.due_at" type="datetime-local" class="input" /></div>
                        <div><label class="label">Priority</label><select v-model="draft.priority" class="input"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select></div>
                        <div><label class="label">About note</label><select v-model="draft.note_id" class="input" data-testid="task-note"><option value="">No note</option><option v-for="n in notes" :key="n.id" :value="n.id">{{ n.title || (n.body ?? '').slice(0, 50) }}</option></select></div>
                    </div>
                    <AttachmentPicker :existing="editing?.attachments ?? []" :pending="pending" />
                </template>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = null">Cancel</button><button class="btn-primary" form="ntform">Save</button></template>
        </Modal>
    </div>
</template>
