<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';

const boards = ref(null);
const objects = ref([]);
const modal = ref(false);
const d = ref({});
const errors = ref({});

const PALETTE = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#a855f7', '#14b8a6', '#64748b'];
async function load() {
    boards.value = (await api.get('/boards')).data;
    objects.value = (await api.get('/data-objects')).data;
}
onMounted(load);

const object = computed(() => objects.value.find((o) => o.id === Number(d.value.data_object_id)));
const selects = computed(() => object.value?.fields.filter((f) => f.type === 'select') ?? []);
const choices = computed(() => selects.value.find((f) => f.key === d.value.key_field)?.options?.choices ?? []);

function open(b = null) {
    errors.value = {};
    const first = objects.value.find((o) => o.fields.some((f) => f.type === 'select'));
    d.value = b ? { ...b, display_fields: [...(b.display_fields ?? [])], colors: { ...(b.colors ?? {}) } }
        : { name: '', description: '', data_object_id: first?.id ?? '', key_field: first?.fields.find((f) => f.type === 'select')?.key ?? '', relationship_id: '', display_fields: [], colors: {}, status: 'active' };
    modal.value = true;
}
function objectChanged() { d.value.key_field = selects.value[0]?.key ?? ''; d.value.display_fields = []; d.value.colors = {}; d.value.relationship_id = ''; }
const toggleField = (k) => { const l = d.value.display_fields; d.value.display_fields = l.includes(k) ? l.filter((x) => x !== k) : [...l, k]; };

async function save() {
    errors.value = {};
    const body = { ...d.value, relationship_id: d.value.relationship_id || null };
    delete body.id; delete body.data_object; delete body.created_at; delete body.updated_at;
    try { await (d.value.id ? api.put(`/boards/${d.value.id}`, body) : api.post('/boards', body)); modal.value = false; load(); run(async () => {}, 'Board saved'); }
    catch (e) { errors.value = e.errors ?? { name: [e.message] }; }
}
async function remove(b) {
    if (await confirmDialog({ title: `Delete board “${b.name}”?`, message: 'Only the board is deleted, not its records.', danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/boards/${b.id}`), 'Deleted'); load(); }
}
</script>

<template>
    <AdminPage title="Workflow boards" subtitle="Manage boards for a clear overview of state across data objects.">
        <template #actions><button class="btn-primary" @click="open()"><Plus class="size-4" /> Add board</button></template>
        <div class="card divide-y divide-slate-100">
            <div v-for="b in boards" :key="b.id" class="flex items-center justify-between gap-4 px-5 py-4 text-sm">
                <div><Link :href="`/boards/${b.id}`" class="font-medium hover:text-brand-700">{{ b.name }}</Link>
                    <div class="mt-0.5 text-xs text-slate-400">{{ b.data_object?.name }} · grouped by {{ b.key_field }} <span :class="b.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : ''" class="badge ml-1">{{ b.status }}</span></div></div>
                <div class="flex gap-1"><button class="btn-ghost !px-1.5" @click="open(b)"><Pencil class="size-4" /></button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(b)"><Trash2 class="size-4" /></button></div>
            </div>
            <p v-if="boards && !boards.length" class="px-5 py-10 text-center text-sm text-slate-400">No boards yet. A board needs a data object with a dropdown field.</p>
        </div>

        <Modal v-if="modal" :title="d.id ? 'Edit board' : 'Add board'" width="max-w-2xl" @close="modal = false">
            <form id="bf" class="space-y-3" @submit.prevent="save">
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Name</label><input v-model="d.name" class="input" required /><p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p></div>
                    <div><label class="label">Status</label><select v-model="d.status" class="input"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
                <div><label class="label">Description</label><input v-model="d.description" class="input" /></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label">Data object type</label><select v-model="d.data_object_id" class="input" @change="objectChanged"><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                    <div><label class="label">Key field (columns)</label><select v-model="d.key_field" class="input"><option v-for="f in selects" :key="f.key" :value="f.key">{{ f.label }}</option></select>
                        <p v-if="!selects.length" class="mt-1 text-xs text-amber-600">This object has no dropdown fields.</p><p v-if="errors.key_field || errors.data_object_id" class="mt-1 text-xs text-red-600">{{ (errors.key_field || errors.data_object_id)[0] }}</p></div>
                </div>
                <div v-if="object?.relationships?.length"><label class="label">Show contacts linked as</label><select v-model="d.relationship_id" class="input"><option value="">Any relationship</option><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                <div><label class="label">Fields shown on cards</label><div class="flex flex-wrap gap-x-4 gap-y-1 text-sm"><label v-for="f in object?.fields ?? []" :key="f.key" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="d.display_fields.includes(f.key)" @change="toggleField(f.key)" /> {{ f.label }}</label></div></div>
                <div v-if="choices.length"><label class="label">Column colours</label>
                    <div class="space-y-1.5"><div v-for="c in choices" :key="c" class="flex items-center gap-3 text-sm"><span class="w-32 truncate">{{ c }}</span>
                        <button v-for="col in PALETTE" :key="col" type="button" :style="{ background: col }" class="size-5 rounded-full ring-offset-1 hover:ring-2" :class="d.colors[c] === col && 'ring-2 ring-slate-500'" @click="d.colors[c] = col" /></div></div></div>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = false">Cancel</button><button class="btn-primary" form="bf">Save board</button></template>
        </Modal>
    </AdminPage>
</template>
