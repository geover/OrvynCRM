<script setup>
import { onMounted, ref } from 'vue';
import AttachmentPicker from '@/Components/AttachmentPicker.vue';
import Modal from '@/Components/Modal.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { forEditor } from '@/lib/richtext';
import { api } from '@/lib/api';

// Add or change a task. For a task that belongs to a contact it can also be about one of that contact's notes and carry files and links.
const props = defineProps({ task: { type: Object, default: null } });             // null: a new task
const emit = defineEmits(['close', 'saved']);

const t = props.task;
const draft = ref({
    title: t?.title ?? '', description: forEditor(t?.description), assigned_to: t?.assigned_to ?? '', priority: t?.priority ?? 'normal',
    due_at: t?.due_at ? t.due_at.slice(0, 16) : '', note_id: t?.note_id ?? '',
});
const pending = ref({ files: [], links: [], removed: [] });
const users = ref([]);
const notes = ref([]);
const errors = ref({});
const saving = ref(false);

onMounted(async () => {
    users.value = (await api.get('/users')).data;
    if (t?.contact_id) notes.value = (await api.get(`/contacts/${t.contact_id}/notes`)).data;
});

async function save() {
    saving.value = true; errors.value = {};
    try {
        const body = { ...draft.value, assigned_to: draft.value.assigned_to || null, due_at: draft.value.due_at || null, note_id: draft.value.note_id || null };
        const saved = t ? await api.patch(`/tasks/${t.id}`, body) : await api.post('/tasks', body);
        if (t?.contact_id) {
            for (const id of pending.value.removed) await api.delete(`/attachments/${id}`);
            for (const file of pending.value.files) { const fd = new FormData(); fd.append('file', file); await api.postForm(`/tasks/${saved.data.id}/attachments`, fd); }
            for (const l of pending.value.links) await api.post(`/tasks/${saved.data.id}/attachments`, { url: l.url, title: l.title || null });
        }
        emit('saved', saved.data);
    } catch (e) { errors.value = e.errors ?? {}; if (!Object.keys(errors.value).length) throw e; } finally { saving.value = false; }
}
</script>

<template>
    <Modal :title="task ? 'Edit task' : 'New task'" width="max-w-2xl" @close="emit('close')">
        <form id="task-editor" class="space-y-3" @submit.prevent="save">
            <div><label class="label">Title</label><input v-model="draft.title" class="input" required data-testid="task-title" /><p v-if="errors.title" class="mt-1 text-xs text-red-600">{{ errors.title[0] }}</p></div>
            <div><label class="label">Description</label><RichTextEditor v-model="draft.description" min-height="min-h-32" max-height="max-h-72" placeholder="What needs doing, links, steps, a checklist…" data-testid="task-description" /></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Assign to</label><select v-model="draft.assigned_to" class="input"><option value="">Me</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                <div><label class="label">Due</label><input v-model="draft.due_at" type="datetime-local" class="input" /></div>
                <div><label class="label">Priority</label><select v-model="draft.priority" class="input"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select></div>
                <div v-if="task?.contact_id"><label class="label">About note</label><select v-model="draft.note_id" class="input"><option value="">No note</option><option v-for="n in notes" :key="n.id" :value="n.id">{{ n.title || (n.body ?? '').slice(0, 50) }}</option></select></div>
            </div>
            <AttachmentPicker v-if="task?.contact_id" :existing="task.attachments ?? []" :pending="pending" />
            <p v-else-if="task" class="text-xs text-slate-400">Files and notes can be added to tasks that belong to a contact.</p>
        </form>
        <template #footer><button class="btn-ghost" @click="emit('close')">Cancel</button><button class="btn-primary" form="task-editor" :disabled="saving" data-testid="task-save">Save</button></template>
    </Modal>
</template>
