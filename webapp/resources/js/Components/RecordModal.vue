<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Download, Trash2, Upload, X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import FieldInput from '@/Components/FieldInput.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run, toast } from '@/lib/ui';

// Create/edit one data object record: Details, linked Contacts, Attachments and History tabs.
const props = defineProps({
    object: Object,                         // {id,name,fields,relationships}
    record: { type: Object, default: null },
    contactId: { type: Number, default: null },   // when opened from a contact: link them on create
});
const emit = defineEmits(['close', 'saved']);

const isNew = !props.record;
const tab = ref('details');
const draft = ref({ ...(props.record?.data ?? {}) });
const relId = ref(props.object.relationships?.[0]?.id ?? '');
const errors = ref({});
const saving = ref(false);
const history = ref(null);
const files = ref(null);
const links = ref(props.record?.contacts ?? []);
const search = ref('');
const found = ref([]);
const connectRel = ref(props.object.relationships?.[0]?.id ?? '');
const base = computed(() => `/data-objects/${props.object.id}/records/${props.record?.id}`);

// Every relationship type can connect a contact, not only the ones this data object lists (those come first; the rest are under "Other relationships").
const allRels = ref([]);
const otherRels = computed(() => allRels.value.filter((r) => !(props.object.relationships ?? []).some((o) => o.id === r.id)));
onMounted(async () => {
    allRels.value = (await api.get('/relationships')).data;
    if (!relId.value) relId.value = allRels.value[0]?.id ?? '';
    if (!connectRel.value) connectRel.value = allRels.value[0]?.id ?? '';
});

onMounted(() => {
    // pre-fill field defaults on create
    if (isNew) for (const f of props.object.fields) if (f.options?.default && draft.value[f.key] === undefined) draft.value[f.key] = f.options.default;
});

async function save() {
    errors.value = {}; saving.value = true;
    try {
        const r = isNew
            ? await api.post(`/data-objects/${props.object.id}/records`, { data: draft.value, contacts: props.contactId && relId.value ? [{ contact_id: props.contactId, relationship_id: relId.value }] : [] })
            : await api.patch(base.value, { data: draft.value });
        toast(isNew ? 'Created' : 'Saved'); emit('saved', r.data);
    } catch (e) { errors.value = e.errors ?? {}; if (!Object.keys(errors.value).length) toast(e.message, 'error'); } finally { saving.value = false; }
}

async function openTab(t) {
    tab.value = t;
    if (t === 'history' && !history.value) history.value = (await api.get(`${base.value}/history`)).data;
    if (t === 'files' && !files.value) await loadFiles();
}
const loadFiles = async () => (files.value = (await api.get(`${base.value}/attachments`)).data);
async function upload(e) {
    for (const file of e.target.files) {
        const fd = new FormData(); fd.append('file', file);
        const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch(`/api/v1${base.value}/attachments`, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
        if (!r.ok) toast((await r.json()).errors?.file?.[0] ?? 'Upload failed', 'error');
    }
    e.target.value = ''; loadFiles();
}
async function removeFile(f) {
    if (await confirmDialog({ title: `Delete “${f.filename}”?`, danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`${base.value}/attachments/${f.id}`), 'Deleted'); loadFiles(); }
}

let t;
const searchContacts = () => { clearTimeout(t); t = setTimeout(async () => { found.value = search.value.length > 1 ? (await api.get('/contacts', { 'filter[q]': search.value, per_page: 6 })).data : []; }, 250); };
async function connect(c) {
    const r = await run(() => api.post(`${base.value}/contacts`, { contact_id: c.id, relationship_id: connectRel.value }), `Connected ${c.full_name}`);
    if (r) { // the attach endpoint returns {data: record}; a single record from GET is NOT wrapped (it already has a `data` key)
    links.value = r.data?.contacts ?? (await api.get(base.value)).contacts; search.value = ''; found.value = []; }
}
async function unlink(c) {
    if (await confirmDialog({ title: `Unlink ${c.name}?`, confirmLabel: 'Unlink' })) { await run(() => api.delete(`${base.value}/contacts/${c.id}`), 'Unlinked'); links.value = links.value.filter((x) => x.id !== c.id); }
}
const relLabel = (id) => props.object.relationships?.find((r) => r.id === id)?.label ?? allRels.value.find((r) => r.id === id)?.label;
const size = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
const show = (v) => (v === null || v === undefined || v === '' ? '—' : Array.isArray(v) ? v.join(', ') : v);
// a field's width in the form (set on the object's field, 25 / 33 / 50 / 100 %): side by side when there is room, one per row on a phone
const WIDTH_CLASS = { 25: 'col-span-12 sm:col-span-3', 33: 'col-span-12 sm:col-span-4', 50: 'col-span-12 sm:col-span-6', 100: 'col-span-12' };
const eventLabel = { 'record.created': 'Created', 'record.updated': 'Updated', 'record.linked': 'Contact linked' };
</script>

<template>
    <Modal :title="`${isNew ? 'Add' : 'Edit'} ${object.name}`" width="max-w-3xl" @close="emit('close')">
        <div v-if="!isNew" class="-mt-2 mb-4 flex gap-1 border-b border-slate-100 text-sm">
            <button v-for="[v, l] in [['details', 'Details'], ['contacts', 'Contacts'], ['files', 'Attachments'], ['history', 'History']]" :key="v" :class="tab === v ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500'" class="-mb-px border-b-2 px-3 py-2 font-medium" @click="openTab(v)">{{ l }}</button>
        </div>

        <form v-show="tab === 'details'" id="rform" class="grid grid-cols-12 gap-3" @submit.prevent="save">
            <div v-if="isNew && contactId && (object.relationships?.length || allRels.length)" class="col-span-12"><label class="label">Link this contact as</label><select v-model="relId" class="input"><optgroup v-if="otherRels.length" label="For this data object"><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></optgroup><optgroup v-if="otherRels.length" label="Other relationships"><option v-for="r in otherRels" :key="r.id" :value="r.id">{{ r.label }}</option></optgroup><template v-else><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></template></select></div>
            <FieldInput v-for="f in object.fields" :key="f.id" :class="WIDTH_CLASS[Number(f.options?.width)] ?? 'col-span-12'" :model-value="draft[f.key]" :field="f" :error="errors[f.key]?.[0]" @update:model-value="(v) => { draft[f.key] = v; delete errors[f.key]; }" />
            <p v-if="!object.fields.length" class="col-span-12 text-sm text-slate-400">This object has no fields yet. Add some in the Object builder.</p>
        </form>

        <div v-if="tab === 'contacts'" class="space-y-3 text-sm">
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                <li v-for="c in links" :key="c.id" class="flex items-center justify-between px-3 py-2"><div><Link :href="`/contacts/${c.id}`" class="font-medium text-brand-700 hover:underline">{{ c.name }}</Link> <span class="badge ml-1">{{ relLabel(c.relationship_id) }}</span></div>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="unlink(c)"><X class="size-4" /></button></li>
                <li v-if="!links.length" class="px-3 py-4 text-center text-slate-400">No contacts linked.</li>
            </ul>
            <div class="flex gap-2"><select v-model="connectRel" class="input !w-40"><optgroup v-if="otherRels.length" label="For this data object"><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></optgroup><optgroup v-if="otherRels.length" label="Other relationships"><option v-for="r in otherRels" :key="r.id" :value="r.id">{{ r.label }}</option></optgroup><template v-else><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></template></select><input v-model="search" class="input" placeholder="Find a contact to connect…" @input="searchContacts" /></div>
            <div v-if="found.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200"><button v-for="c in found" :key="c.id" class="flex w-full justify-between px-3 py-2 text-left hover:bg-slate-50" @click="connect(c)"><span>{{ c.full_name }}</span><span class="text-xs text-slate-400">{{ c.email }}</span></button></div>
        </div>

        <div v-if="tab === 'files'" class="space-y-3 text-sm">
            <label class="btn-outline cursor-pointer"><Upload class="size-4" /> Upload file<input type="file" multiple class="hidden" @change="upload" /></label>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                <li v-for="f in files ?? []" :key="f.id" class="flex items-center justify-between px-3 py-2"><a :href="`/files${base}/attachments/${f.id}`" class="flex items-center gap-2 font-medium text-brand-700 hover:underline"><Download class="size-4" /> {{ f.filename }}</a>
                    <span class="flex items-center gap-3 text-xs text-slate-400">{{ size(f.size) }} · {{ fmtDate(f.created_at) }}<button class="text-slate-400 hover:text-red-600" @click="removeFile(f)"><Trash2 class="size-4" /></button></span></li>
                <li v-if="files && !files.length" class="px-3 py-6 text-center text-slate-400">No attachments.</li>
            </ul>
        </div>

        <ul v-if="tab === 'history'" class="space-y-3 text-sm">
            <li v-for="h in history ?? []" :key="h.id" class="rounded-lg border border-slate-200 p-3">
                <div class="flex justify-between"><span class="font-medium">{{ eventLabel[h.type] ?? h.type }}</span><span class="text-xs text-slate-400">{{ fmtDate(h.at, true) }}{{ h.by ? ` · ${h.by}` : '' }}</span></div>
                <div v-for="c in h.changes" :key="c.field" class="mt-1 text-xs"><span class="text-slate-500">{{ c.field }}:</span> <span class="text-red-600 line-through">{{ show(c.from) }}</span> → <span class="text-emerald-700">{{ show(c.to) }}</span></div>
            </li>
            <li v-if="history && !history.length" class="py-6 text-center text-slate-400">No history yet.</li>
        </ul>

        <template v-if="tab === 'details'" #footer><button class="btn-ghost" @click="emit('close')">Cancel</button><button class="btn-primary" form="rform" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button></template>
    </Modal>
</template>
