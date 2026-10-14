<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Link2, Pencil, Plus, Trash2, Unlink } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import RecordModal from '@/Components/RecordModal.vue';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';

// Records of ONE data object linked to this contact (the old per-object sidebar tab).
const props = defineProps({ contactId: Number, objectId: Number });
const emit = defineEmits(['changed']);

const object = ref(null);
const relationships = ref([]);
const rows = ref([]);
const next = ref(null);
const loading = ref(true);
const q = ref('');
const modal = ref(null);          // 'edit' | 'connect' | null
const target = ref(null);         // record being edited / connected
const draft = ref({});
const relId = ref('');
const errors = ref({});
const found = ref([]);
const search = ref('');
const connectRel = ref('');

const columns = computed(() => {
    const f = object.value?.fields.filter((x) => x.show_in_list) ?? [];
    return f.length ? f : (object.value?.fields.slice(0, 4) ?? []);
});

async function load(reset = true) {
    loading.value = true;
    const r = await api.get(`/data-objects/${props.objectId}/records`, { contact_id: props.contactId, q: q.value, cursor: reset ? undefined : next.value });
    rows.value = reset ? r.data : [...rows.value, ...r.data];
    next.value = r.meta?.next_cursor ?? null;
    loading.value = false;
}
async function init() {
    object.value = (await api.get(`/data-objects/${props.objectId}`)).data;
    relationships.value = object.value.relationships;
    relId.value = relationships.value[0]?.id ?? '';
    q.value = '';
    await load();
}
onMounted(init);
watch(() => props.objectId, init);
let t;
watch(q, () => { clearTimeout(t); t = setTimeout(load, 300); });

function openAdd() { target.value = null; modal.value = 'edit'; }
function openEdit(r) { target.value = r; modal.value = 'edit'; }

async function remove(r) {
    if (!(await confirmDialog({ title: 'Delete this item?', message: 'It is removed for every contact it is linked to.', danger: true, confirmLabel: 'Delete' }))) return;
    await run(() => api.delete(`/data-objects/${props.objectId}/records/${r.id}`), 'Deleted');
    load(); emit('changed');
}
async function unlink(r) {
    if (!(await confirmDialog({ title: 'Unlink this contact from the item?', confirmLabel: 'Unlink' }))) return;
    await run(() => api.delete(`/data-objects/${props.objectId}/records/${r.id}/contacts/${props.contactId}`), 'Unlinked');
    load(); emit('changed');
}

// connect ANOTHER contact to an item
function openConnect(r) { target.value = r; found.value = []; search.value = ''; connectRel.value = relationships.value[0]?.id ?? ''; modal.value = 'connect'; }
let st;
watch(search, () => {
    clearTimeout(st);
    st = setTimeout(async () => { found.value = search.value.length > 1 ? (await api.get('/contacts', { 'filter[q]': search.value, per_page: 8 })).data : []; }, 250);
});
async function connect(c) {
    const ok = await run(() => api.post(`/data-objects/${props.objectId}/records/${target.value.id}/contacts`, { contact_id: c.id, relationship_id: connectRel.value }), `Connected ${c.full_name}`);
    if (ok) { modal.value = null; load(); }
}
const relLabel = (id) => relationships.value.find((r) => r.id === id)?.label;
const show = (v) => (Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? 'Yes' : 'No') : v);
</script>

<template>
    <div v-if="object" class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="q" class="input !w-64" :placeholder="`Search ${object.name}…`" />
            <Link :href="`/objects/${objectId}`" class="text-sm text-slate-500 hover:text-slate-800">Open all {{ object.name }} →</Link>
            <button class="btn-primary ml-auto" @click="openAdd"><Plus class="size-4" /> Add item</button>
        </div>

        <div class="card table-scroll">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr><th v-for="f in columns" :key="f.id" class="px-4 py-3">{{ f.label }}</th><th class="px-4 py-3">Linked contacts</th><th /></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-slate-50">
                        <td v-for="f in columns" :key="f.id" class="px-4 py-3">{{ show(r.data[f.key]) }}</td>
                        <td class="px-4 py-3">
                            <Link v-for="c in r.contacts" :key="c.id" :href="`/contacts/${c.id}`" :class="c.id === contactId ? '!bg-brand-50 !text-brand-700' : ''" class="badge mr-1" :title="relLabel(c.relationship_id)">{{ c.name }}</Link>
                        </td>
                        <td class="whitespace-nowrap px-2 text-right">
                            <button class="btn-ghost !px-1.5" title="Edit" @click="openEdit(r)"><Pencil class="size-4" /></button>
                            <button class="btn-ghost !px-1.5" title="Connect another contact" @click="openConnect(r)"><Link2 class="size-4" /></button>
                            <button class="btn-ghost !px-1.5" title="Unlink this contact" @click="unlink(r)"><Unlink class="size-4" /></button>
                            <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" @click="remove(r)"><Trash2 class="size-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!rows.length && !loading"><td :colspan="columns.length + 2" class="px-4 py-10 text-center text-slate-400">No {{ object.name.toLowerCase() }} linked to this contact.</td></tr>
                </tbody>
            </table>
            <div v-if="next" class="border-t border-slate-100 p-3 text-center"><button class="btn-outline" @click="load(false)">Load more</button></div>
        </div>

        <RecordModal v-if="modal === 'edit'" :object="object" :record="target" :contact-id="contactId" @close="modal = null" @saved="modal = null; load(); emit('changed')" />

        <Modal v-if="modal === 'connect'" title="Connect another contact" @close="modal = null">
            <div class="space-y-3">
                <div><label class="label">Add as</label><select v-model="connectRel" class="input"><option v-for="r in relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                <div><label class="label">Find contact</label><input v-model="search" class="input" placeholder="Name or email…" autofocus /></div>
                <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    <button v-for="c in found" :key="c.id" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50" @click="connect(c)">
                        <span class="font-medium">{{ c.full_name }}</span><span class="text-xs text-slate-400">{{ c.email }}</span>
                    </button>
                    <p v-if="!found.length" class="px-3 py-4 text-center text-sm text-slate-400">Type at least 2 characters.</p>
                </div>
            </div>
        </Modal>
    </div>
    <div v-else class="text-sm text-slate-400">Loading…</div>
</template>
