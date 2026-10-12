<script setup>
import { computed, onMounted, ref } from 'vue';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';

const items = ref(null);
const objects = ref([]);
const modal = ref(false);
const d = ref({});
const errors = ref({});

const ready = ref(false);
async function load() { [items.value, objects.value] = await Promise.all([api.get('/schedules').then((r) => r.data), api.get('/data-objects').then((r) => r.data)]); ready.value = true; }
onMounted(load);
const object = computed(() => objects.value.find((o) => o.id === Number(d.value.data_object_id)));
const dateFields = computed(() => object.value?.fields.filter((f) => ['date', 'datetime'].includes(f.type)) ?? []);

function open(s = null) {
    errors.value = {};
    const first = objects.value.find((o) => o.fields.some((f) => ['date', 'datetime'].includes(f.type)));
    d.value = s ? { ...s } : { name: '', description: '', data_object_id: first?.id ?? '', start_field: first?.fields.find((f) => ['date', 'datetime'].includes(f.type))?.key ?? '', end_field: '', contact_relationship_id: '', user_relationship_id: '', contact_specific: false, type: 'date' };
    modal.value = true;
}
const objChanged = () => { d.value.start_field = dateFields.value[0]?.key ?? ''; d.value.end_field = ''; };
async function save() {
    errors.value = {};
    const body = { ...d.value, end_field: d.value.end_field || null, contact_relationship_id: d.value.contact_relationship_id || null, user_relationship_id: d.value.user_relationship_id || null };
    ['id', 'data_object', 'created_at', 'updated_at'].forEach((k) => delete body[k]);
    try { await (d.value.id ? api.put(`/schedules/${d.value.id}`, body) : api.post('/schedules', body)); modal.value = false; load(); run(async () => {}, 'Schedule saved'); }
    catch (e) { errors.value = e.errors ?? { name: [e.message] }; }
}
async function remove(s) { if (await confirmDialog({ title: `Delete “${s.name}”?`, danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/schedules/${s.id}`), 'Deleted'); load(); } }
</script>

<template>
    <AdminPage title="Scheduler" subtitle="Choose which data object dates appear on the Schedules calendar.">
        <template #actions><button class="btn-primary" :disabled="!ready" @click="open()"><Plus class="size-4" /> Add schedule</button></template>
        <div class="card divide-y divide-slate-100">
            <div v-for="s in items" :key="s.id" class="flex items-center justify-between gap-4 px-5 py-4 text-sm">
                <div><div class="font-medium">{{ s.name }}</div><div class="mt-0.5 text-xs text-slate-400">{{ s.data_object?.name }} · starts {{ s.start_field }}{{ s.end_field ? ` · ends ${s.end_field}` : '' }} · {{ s.type }}</div></div>
                <div class="flex gap-1"><button class="btn-ghost !px-1.5" :disabled="!ready" @click="open(s)"><Pencil class="size-4" /></button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(s)"><Trash2 class="size-4" /></button></div>
            </div>
            <p v-if="items && !items.length" class="px-5 py-10 text-center text-sm text-slate-400">No schedules yet. A schedule needs a data object with a date field.</p>
        </div>
        <Modal v-if="modal" :title="d.id ? 'Edit schedule' : 'Add schedule'" @close="modal = false">
            <form id="sf" class="space-y-3" @submit.prevent="save">
                <div><label class="label">Name</label><input v-model="d.name" class="input" required /><p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p></div>
                <div><label class="label">Description</label><input v-model="d.description" class="input" /></div>
                <div><label class="label">Data object type</label><select v-model="d.data_object_id" class="input" @change="objChanged"><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label">Start date field</label><select v-model="d.start_field" class="input" required><option v-for="f in dateFields" :key="f.key" :value="f.key">{{ f.label }}</option></select><p v-if="errors.start_field" class="mt-1 text-xs text-red-600">{{ errors.start_field[0] }}</p></div>
                    <div><label class="label">End date field</label><select v-model="d.end_field" class="input"><option value="">None</option><option v-for="f in dateFields" :key="f.key" :value="f.key">{{ f.label }}</option></select></div>
                </div>
                <div v-if="object?.relationships?.length" class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label">Contact relationship</label><select v-model="d.contact_relationship_id" class="input"><option value="">Any</option><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                    <div><label class="label">User relationship</label><select v-model="d.user_relationship_id" class="input"><option value="">Everyone sees all</option><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select>
                        <p class="mt-1 text-xs text-slate-400">Only show items linked to the logged-in user's contact.</p></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Type</label><select v-model="d.type" class="input"><option value="date">Date</option><option value="datetime">Date &amp; time</option></select></div>
                    <label class="mt-6 flex items-center gap-2 text-sm"><input v-model="d.contact_specific" type="checkbox" class="rounded" /> Contact specific</label></div>
                <p v-if="errors.name === undefined && Object.keys(errors).length" class="text-xs text-red-600">{{ Object.values(errors)[0][0] }}</p>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = false">Cancel</button><button class="btn-primary" form="sf">Save</button></template>
        </Modal>
    </AdminPage>
</template>
