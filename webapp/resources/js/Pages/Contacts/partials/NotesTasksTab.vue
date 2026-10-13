<script setup>
import { computed, onMounted, ref } from 'vue';
import { CheckCircle2, Circle, ListTodo, Pencil, Plus, StickyNote, Trash2, X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const props = defineProps({ contactId: Number });
const emit = defineEmits(['changed']);

const notes = ref([]);
const tasks = ref([]);
const users = ref([]);
const knownTags = ref([]);
const loading = ref(true);
const nextNotes = ref(null);     // cursor of the next page of notes
const filter = ref('all');       // all | notes | tasks | open
const q = ref('');
const modal = ref(null);         // 'note' | 'task' | null
const editing = ref(null);
const draft = ref({});
const errors = ref({});

async function load() {
    const [n, t] = await Promise.all([api.get(`/contacts/${props.contactId}/notes`), api.get('/tasks', { 'filter[contact_id]': props.contactId, per_page: 100 })]);
    notes.value = n.data; nextNotes.value = n.next_cursor ?? null; tasks.value = t.data; loading.value = false;
}
async function moreNotes() {
    const n = await api.get(`/contacts/${props.contactId}/notes`, { cursor: nextNotes.value });
    notes.value = [...notes.value, ...n.data]; nextNotes.value = n.next_cursor ?? null;
}
onMounted(async () => {
    load();
    users.value = (await api.get('/users')).data;
    knownTags.value = (await api.get('/note-tags')).data;
});

const items = computed(() => {
    const all = [
        ...notes.value.map((n) => ({ kind: 'note', id: n.id, title: n.title, body: n.body, tags: n.tags ?? [], by: n.author?.name, at: n.created_at, raw: n })),
        ...tasks.value.map((t) => ({ kind: 'task', id: t.id, title: t.title, body: t.description, tags: [], by: t.assignee?.name, at: t.due_at ?? t.created_at, done: !!t.completed_at, due: t.due_at, priority: t.priority, raw: t })),
    ];
    const term = q.value.trim().toLowerCase();
    return all
        .filter((i) => (filter.value === 'all' ? true : filter.value === 'notes' ? i.kind === 'note' : filter.value === 'tasks' ? i.kind === 'task' : i.kind === 'task' && !i.done))
        .filter((i) => !term || [i.title, i.body, ...(i.tags ?? [])].some((s) => (s ?? '').toLowerCase().includes(term)))
        .sort((a, b) => new Date(b.at) - new Date(a.at));
});

function openNew(kind) {
    editing.value = null; errors.value = {};
    draft.value = kind === 'note' ? { title: '', body: '', tags: [], tagInput: '' } : { title: '', description: '', assigned_to: '', due_at: '', priority: 'normal' };
    modal.value = kind;
}
function openEdit(i) {
    editing.value = i; errors.value = {};
    draft.value = i.kind === 'note' ? { title: i.raw.title ?? '', body: i.raw.body, tags: [...(i.raw.tags ?? [])], tagInput: '' }
        : { title: i.raw.title, description: i.raw.description ?? '', assigned_to: i.raw.assigned_to ?? '', due_at: i.raw.due_at ? i.raw.due_at.slice(0, 16) : '', priority: i.raw.priority };
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
            editing.value ? await api.patch(`/contacts/${props.contactId}/notes/${editing.value.id}`, body) : await api.post(`/contacts/${props.contactId}/notes`, body);
        } else {
            const body = { ...draft.value, assigned_to: draft.value.assigned_to || null, due_at: draft.value.due_at || null, contact_id: props.contactId };
            editing.value ? await api.patch(`/tasks/${editing.value.id}`, body) : await api.post('/tasks', body);
        }
        modal.value = null; await load(); emit('changed');
    } catch (e) { errors.value = e.errors ?? {}; if (!Object.keys(errors.value).length) throw e; }
}
async function toggle(i) {
    await run(() => api.patch(`/tasks/${i.id}`, { completed: !i.done }));
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
            <div v-for="i in items" :key="i.kind + i.id" class="card flex gap-3 p-4 text-sm">
                <button v-if="i.kind === 'task'" class="mt-0.5 shrink-0" :title="i.done ? 'Mark open' : 'Complete'" @click="toggle(i)">
                    <CheckCircle2 v-if="i.done" class="size-5 text-emerald-600" /><Circle v-else class="size-5 text-slate-300 hover:text-emerald-500" />
                </button>
                <StickyNote v-else class="mt-0.5 size-5 shrink-0 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span :class="i.done && 'text-slate-400 line-through'" class="font-medium">{{ i.title || (i.kind === 'note' ? 'Note' : 'Task') }}</span>
                        <span v-if="i.kind === 'task' && i.priority === 'high'" class="badge !bg-red-50 !text-red-600">high</span>
                        <span v-if="overdue(i)" class="badge !bg-red-50 !text-red-600">overdue</span>
                        <span v-for="t in i.tags" :key="t" class="badge !bg-amber-50 !text-amber-700">{{ t }}</span>
                    </div>
                    <p v-if="i.body" class="mt-1 whitespace-pre-wrap text-slate-600">{{ i.body }}</p>
                    <p class="mt-1.5 text-xs text-slate-400">
                        <template v-if="i.kind === 'task'">Due {{ i.due ? fmtDate(i.due, true) : 'no date' }} · </template>{{ i.by }} · {{ fmtDate(i.raw.created_at, true) }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-0.5">
                    <button class="btn-ghost !px-1.5" title="Edit" @click="openEdit(i)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" @click="remove(i)"><Trash2 class="size-4" /></button>
                </div>
            </div>
            <div v-if="!items.length" class="card px-5 py-10 text-center text-sm text-slate-400">Nothing here yet.</div>
            <div v-if="nextNotes" class="text-center"><button class="btn-outline" @click="moreNotes">Load more notes</button></div>
        </div>

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
                </template>
                <template v-else>
                    <div><label class="label">Description</label><textarea v-model="draft.description" rows="3" class="input" /></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label">Assign to</label><select v-model="draft.assigned_to" class="input"><option value="">Me</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                        <div><label class="label">Due</label><input v-model="draft.due_at" type="datetime-local" class="input" /></div>
                        <div><label class="label">Priority</label><select v-model="draft.priority" class="input"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select></div>
                    </div>
                </template>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = null">Cancel</button><button class="btn-primary" form="ntform">Save</button></template>
        </Modal>
    </div>
</template>
