<script setup>
import { onMounted, ref } from 'vue';
import { FileText, Link2, Trash2, Upload } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run, toast } from '@/lib/ui';

const props = defineProps({ contactId: Number });
const items = ref(null);
const linkModal = ref(false);
const link = ref({ url: '', title: '' });
const uploading = ref(false);

async function load() { items.value = (await api.get(`/contacts/${props.contactId}/attachments`)).data; }
onMounted(load);

async function upload(e) {
    uploading.value = true;
    for (const file of e.target.files) {
        const fd = new FormData(); fd.append('file', file);
        const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch(`/api/v1/contacts/${props.contactId}/attachments`, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
        if (!r.ok) toast((await r.json()).message || 'Upload failed', 'error');
    }
    e.target.value = ''; uploading.value = false; load();
}
async function addLink() {
    if (await run(() => api.post(`/contacts/${props.contactId}/attachments`, link.value), 'Link attached')) { linkModal.value = false; link.value = { url: '', title: '' }; load(); }
}
async function remove(a) {
    if (!(await confirmDialog({ title: `Delete “${a.title}”?`, danger: true, confirmLabel: 'Delete' }))) return;
    await run(() => api.delete(`/contacts/${props.contactId}/attachments/${a.id}`), 'Deleted'); load();
}
const size = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-end gap-2">
            <button class="btn-outline" @click="linkModal = true"><Link2 class="size-4" /> Attach link</button>
            <label class="btn-primary cursor-pointer" :class="uploading && 'opacity-50'"><Upload class="size-4" /> Upload files<input type="file" multiple class="hidden" @change="upload" /></label>
        </div>
        <div v-if="items" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div v-for="a in items" :key="a.id" class="card flex items-center gap-3 p-4 text-sm">
                <div class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500"><component :is="a.is_link ? Link2 : FileText" class="size-5" /></div>
                <div class="min-w-0 flex-1">
                    <a :href="a.is_link ? a.url : `/files/contacts/${contactId}/attachments/${a.id}`" :target="a.is_link ? '_blank' : undefined" rel="noopener noreferrer" class="block truncate font-medium text-brand-700 hover:underline">{{ a.title }}</a>
                    <div class="truncate text-xs text-slate-400">{{ a.is_link ? a.url : size(a.size) }} · {{ fmtDate(a.created_at) }} · {{ a.uploaded_by }}</div>
                </div>
                <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(a)"><Trash2 class="size-4" /></button>
            </div>
            <div v-if="!items.length" class="card col-span-full px-5 py-10 text-center text-sm text-slate-400">No attachments.</div>
        </div>
        <Modal v-if="linkModal" title="Attach a link" @close="linkModal = false">
            <form id="lf" class="space-y-3" @submit.prevent="addLink">
                <div><label class="label">URL</label><input v-model="link.url" type="url" class="input" placeholder="https://" required /></div>
                <div><label class="label">Title</label><input v-model="link.title" class="input" /></div>
            </form>
            <template #footer><button class="btn-ghost" @click="linkModal = false">Cancel</button><button class="btn-primary" form="lf">Attach</button></template>
        </Modal>
    </div>
</template>
