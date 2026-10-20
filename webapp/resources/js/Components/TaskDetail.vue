<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, FileText, Link2, Pencil, StickyNote, Trash2 } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import { toHtml } from '@/lib/richtext';
import { fmtDate } from '@/lib/ui';

// A task read in full: its text, who and when, the contact and note it belongs to, and its attachments.
const props = defineProps({ task: { type: Object, required: true } });
const emit = defineEmits(['close', 'edit', 'toggle', 'delete']);

const done = computed(() => !!props.task.completed_at);
const overdue = computed(() => !done.value && props.task.due_at && new Date(props.task.due_at) < new Date());
const PRIORITY = { low: 'Low', normal: 'Normal', high: 'High' };
const href = (a) => a.url || `/files/attachments/${a.id}`;
const isImage = (a) => !a.url && (a.mime ?? '').startsWith('image/');
const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round((b ?? 0) / 1024))} KB`);
</script>

<template>
    <Modal :title="task.title" width="max-w-2xl" @close="emit('close')">
        <div class="space-y-4 text-sm" data-testid="task-detail">
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge" :class="done ? '!bg-emerald-50 !text-emerald-700' : overdue ? '!bg-red-50 !text-red-600' : ''">{{ done ? 'Completed' : overdue ? 'Overdue' : 'Open' }}</span>
                <span v-if="task.priority === 'high'" class="badge !bg-red-50 !text-red-600">High priority</span>
            </div>

            <dl class="grid gap-x-6 gap-y-2 text-slate-600 sm:grid-cols-2">
                <div><dt class="text-xs uppercase tracking-wide text-slate-400">Due</dt><dd>{{ task.due_at ? fmtDate(task.due_at, true) : 'No date' }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wide text-slate-400">Assigned to</dt><dd>{{ task.assignee?.name ?? 'Nobody' }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wide text-slate-400">Priority</dt><dd>{{ PRIORITY[task.priority] ?? task.priority }}</dd></div>
                <div v-if="task.completed_at"><dt class="text-xs uppercase tracking-wide text-slate-400">Completed</dt><dd>{{ fmtDate(task.completed_at, true) }}</dd></div>
                <div v-if="task.contact"><dt class="text-xs uppercase tracking-wide text-slate-400">Contact</dt><dd><Link :href="`/contacts/${task.contact_id}`" class="text-brand-700 hover:underline" data-testid="task-detail-contact">{{ task.contact.first_name }} {{ task.contact.last_name }}</Link></dd></div>
                <div><dt class="text-xs uppercase tracking-wide text-slate-400">Created</dt><dd>{{ fmtDate(task.created_at, true) }}</dd></div>
            </dl>

            <div v-if="task.description" class="rich-view rounded-lg bg-slate-50 p-4 text-slate-700" data-testid="task-detail-body" v-html="toHtml(task.description)" />
            <p v-else class="text-slate-400">No description.</p>

            <div v-if="task.note"><Link :href="`/contacts/${task.contact_id}?tab=notes`" class="badge inline-flex items-center gap-1.5 !bg-amber-50 !text-amber-700 hover:!bg-amber-100"><StickyNote class="size-3" /> About note: {{ task.note.title || 'Note' }}</Link></div>

            <div v-if="task.attachments?.length">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Attachments</h3>
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="a in task.attachments" :key="a.id">
                        <a :href="href(a)" :target="a.url ? '_blank' : undefined" rel="noopener noreferrer" class="flex items-center gap-3 rounded-lg border border-slate-200 p-2 text-brand-700 hover:bg-slate-50">
                            <img v-if="isImage(a)" :src="href(a)" :alt="a.title" class="size-14 shrink-0 rounded object-cover" loading="lazy" />
                            <span v-else class="grid size-14 shrink-0 place-items-center rounded bg-slate-100 text-slate-400"><component :is="a.url ? Link2 : FileText" class="size-6" /></span>
                            <span class="min-w-0"><span class="block truncate font-medium">{{ a.title }}</span><span class="block truncate text-xs text-slate-400">{{ a.url ? a.url : size(a.size) }}</span></span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <template #footer>
            <button class="btn-ghost mr-auto !text-red-600 hover:!bg-red-50" data-testid="task-detail-delete" @click="emit('delete', task)"><Trash2 class="size-4" /> Delete</button>
            <button class="btn-outline" data-testid="task-detail-toggle" @click="emit('toggle', task)"><CheckCircle2 class="size-4" /> {{ done ? 'Reopen' : 'Complete' }}</button>
            <button class="btn-primary" data-testid="task-detail-edit" @click="emit('edit', task)"><Pencil class="size-4" /> Edit</button>
        </template>
    </Modal>
</template>
