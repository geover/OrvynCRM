<script setup>
import { onMounted, ref } from 'vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { Copy, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, run, toast } from '@/lib/ui';

const items = ref(null);
const groups = ref([]);
const merge = ref([]);
const modal = ref(false);
const d = ref({});
const preview = ref(false);
const bodyEl = ref(null);

async function load() { const r = await api.get('/email-templates'); items.value = r.data; groups.value = r.groups; }
onMounted(async () => { load(); merge.value = (await api.get('/email-templates/merge-fields')).data; });

function open(t = null) { d.value = t ? { ...t } : { name: '', group: '', subject: '', html: '<p>Hi {{contact.first_name}},</p>' }; preview.value = false; modal.value = true; }
const editor = ref(null);
const insert = (token) => (editor.value ? editor.value.insertText(token) : (d.value.html += token));
async function save() {
    if (!d.value.html?.replace(/<[^>]*>/g, '').trim()) return toast('The template has no text yet.', 'error');
    const body = { name: d.value.name, group: d.value.group || null, subject: d.value.subject || null, html: d.value.html };
    if (await run(() => (d.value.id ? api.put(`/email-templates/${d.value.id}`, body) : api.post('/email-templates', body)), 'Template saved')) { modal.value = false; load(); }
}
async function dup(t) { await run(() => api.post(`/email-templates/${t.id}/duplicate`), 'Duplicated'); load(); }
async function remove(t) { if (await confirmDialog({ title: `Delete “${t.name}”?`, danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/email-templates/${t.id}`), 'Deleted'); load(); } }
</script>

<template>
    <AdminPage title="Email templates" subtitle="Reusable templates, grouped for the email composer.">
        <template #actions><button class="btn-primary" @click="open()"><Plus class="size-4" /> New template</button></template>
        <div class="card divide-y divide-slate-100">
            <div v-for="t in items" :key="t.id" class="flex items-center justify-between gap-3 px-5 py-4 text-sm">
                <div class="min-w-0"><div class="font-medium">{{ t.name }} <span v-if="t.group" class="badge ml-1">{{ t.group }}</span></div><div class="truncate text-xs text-slate-400">{{ t.subject || 'No subject' }}</div></div>
                <div class="flex gap-1"><button class="btn-ghost !px-1.5" @click="open(t)"><Pencil class="size-4" /></button><button class="btn-ghost !px-1.5" title="Duplicate" @click="dup(t)"><Copy class="size-4" /></button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(t)"><Trash2 class="size-4" /></button></div>
            </div>
            <p v-if="items && !items.length" class="px-5 py-10 text-center text-sm text-slate-400">No templates yet.</p>
        </div>

        <Modal v-if="modal" :title="d.id ? 'Edit template' : 'New template'" width="max-w-3xl" @close="modal = false">
            <form id="ef" class="space-y-3" @submit.prevent="save">
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Template name</label><input v-model="d.name" class="input" required /></div>
                    <div><label class="label">Group</label><input v-model="d.group" list="tg" class="input" placeholder="e.g. Onboarding" /><datalist id="tg"><option v-for="g in groups" :key="g" :value="g" /></datalist></div></div>
                <div><label class="label">Subject</label><input v-model="d.subject" class="input" /></div>
                <div>
                    <div class="mb-1 flex items-center justify-between"><label class="label !mb-0">Message</label>
                        <div class="flex gap-2 text-sm"><select class="input !w-48 !py-1" @change="insert($event.target.value); $event.target.value = ''"><option value="">Insert merge field…</option><optgroup v-for="g in merge" :key="g.group" :label="g.group"><option v-for="f in g.fields" :key="f.token" :value="f.token">{{ f.label }}</option></optgroup></select>
                            <button type="button" class="btn-outline !py-1" @click="preview = !preview">{{ preview ? 'Edit' : 'Preview' }}</button></div></div>
                    <RichTextEditor v-if="!preview" ref="editor" v-model="d.html" min-height="min-h-72" max-height="max-h-[60vh]" />
                    <iframe v-else sandbox="" :srcdoc="d.html" class="h-80 w-full rounded-lg border border-slate-200 bg-white" title="Preview" />
                </div>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = false">Cancel</button><button class="btn-primary" form="ef">Save template</button></template>
        </Modal>
    </AdminPage>
</template>
