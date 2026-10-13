<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Download, Filter, Pencil, Plus, Settings, Trash2 } from 'lucide-vue-next';
import DataTable from '@/Components/DataTable.vue';
import FilterBuilder from '@/Components/FilterBuilder.vue';
import PageHeader from '@/Components/PageHeader.vue';
import RecordModal from '@/Components/RecordModal.vue';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';

const props = defineProps({ objectId: Number });
const object = ref(null);
const rows = ref([]);
const next = ref(null);
const total = ref(null);
const q = ref('');
const filter = ref({ match: 'all', rules: [] });
const showFilter = ref(false);
const modal = ref(null);          // {record|null}
const loading = ref(true);
const selected = ref([]);

const listFields = computed(() => {
    const f = object.value?.fields.filter((x) => x.show_in_list) ?? [];
    return f.length ? f : (object.value?.fields.slice(0, 5) ?? []);
});
const columns = computed(() => [...listFields.value.map((f, i) => ({ key: `f:${f.key}`, label: f.label, title: i === 0 })), { key: 'contacts', label: 'Contacts' }]);
const activeRules = computed(() => (filter.value.rules ?? []).filter((r) => r.rules || (r.field && (['empty', 'not_empty'].includes(r.op) || (r.value !== '' && r.value !== null)))));

async function load(reset = true) {
    loading.value = true;
    const params = { q: q.value, cursor: reset ? undefined : next.value };
    if (activeRules.value.length) { params['filter[match]'] = filter.value.match; activeRules.value.forEach((r, i) => { if (!r.rules) { params[`filter[rules][${i}][field]`] = r.field; params[`filter[rules][${i}][op]`] = r.op; const v = r.value; Array.isArray(v) ? v.forEach((x, j) => (params[`filter[rules][${i}][value][${j}]`] = x)) : (params[`filter[rules][${i}][value]`] = v); } }); }
    try {
        const res = await api.get(`/data-objects/${props.objectId}/records`, params);
        rows.value = reset ? res.data : [...rows.value, ...res.data];
        next.value = res.meta?.next_cursor ?? null;
        if (reset) selected.value = [];
    } finally { loading.value = false; }
}
onMounted(async () => { object.value = (await api.get(`/data-objects/${props.objectId}`)).data; load(); });
let t;
watch(q, () => { clearTimeout(t); t = setTimeout(load, 300); });

async function remove(r) {
    if (!(await confirmDialog({ title: 'Delete this item?', message: 'It is removed for every contact it is linked to.', danger: true, confirmLabel: 'Delete' }))) return;
    await run(() => api.delete(`/data-objects/${props.objectId}/records/${r.id}`), 'Deleted'); load();
}
async function removeSelected() {
    const n = selected.value.length;
    if (!(await confirmDialog({ title: `Delete ${n} item${n === 1 ? '' : 's'}?`, message: 'They are removed for every contact they are linked to.', danger: true, confirmLabel: `Delete ${n}` }))) return;
    const ok = await run(async () => { for (const id of selected.value) await api.delete(`/data-objects/${props.objectId}/records/${id}`); }, `${n} deleted`);
    if (ok) load();
}
const show = (v) => (Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? 'Yes' : 'No') : v);
</script>

<template>
    <Head :title="object?.name ?? 'Data Object'" />
    <template v-if="object">
        <PageHeader :title="object.name" :subtitle="object.description" :crumbs="[{ label: 'Data objects', href: '/objects' }, { label: object.name }]">
            <Link href="/admin/data-tools" class="btn-outline"><Download class="size-4" /> Import / export</Link>
            <Link :href="`/objects/${objectId}/edit`" class="btn-outline"><Settings class="size-4" /> Fields &amp; settings</Link>
            <button class="btn-primary" @click="modal = { record: null }"><Plus class="size-4" /> New item</button>
        </PageHeader>

        <div class="mb-4 space-y-3">
            <div class="flex flex-wrap gap-2">
                <input v-model="q" class="input !w-72" :placeholder="`Search ${object.name.toLowerCase()}…`" />
                <button class="btn-outline" :class="activeRules.length && '!border-brand-500 !text-brand-700'" @click="showFilter = !showFilter"><Filter class="size-4" /> Filters<span v-if="activeRules.length" class="badge ml-1 !bg-brand-50 !text-brand-700">{{ activeRules.length }}</span></button>
                <button v-if="activeRules.length" class="btn-ghost" @click="filter = { match: 'all', rules: [] }; load()">Clear</button>
            </div>
            <div v-if="showFilter" class="card space-y-3 p-4">
                <FilterBuilder v-model="filter" :fields="object.fields" />
                <div class="flex justify-end"><button class="btn-primary" @click="load()">Apply filters</button></div>
            </div>
        </div>

        <DataTable v-model:selected="selected" :columns="columns" :rows="rows" :loading="loading" selectable row-clickable noun="item" :storage-key="`object-${objectId}`" :empty-text="`No items${activeRules.length || q ? ' match your search' : ' yet'}.`" @row-click="(r) => (modal = { record: r })">
            <template v-for="f in listFields" :key="f.key" #[`cell-f:${f.key}`]="{ row: r }">{{ show(r.data[f.key]) }}</template>
            <template #cell-contacts="{ row: r }"><span @click.stop><Link v-for="c in r.contacts" :key="c.id" :href="`/contacts/${c.id}`" class="badge mr-1 hover:bg-brand-50">{{ c.name }}</Link></span></template>
            <template #bulk><button class="btn-outline !py-1.5 !text-red-600 hover:!bg-red-50" @click="removeSelected"><Trash2 class="size-4" /> Delete</button></template>
            <template #actions="{ row: r }"><button class="btn-ghost !px-1.5" title="Edit" @click="modal = { record: r }"><Pencil class="size-4" /></button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" @click="remove(r)"><Trash2 class="size-4" /></button></template>
            <template #footer><div v-if="next" class="border-t border-slate-100 p-3 text-center"><button class="btn-outline" @click="load(false)">Load more</button></div></template>
        </DataTable>

        <RecordModal v-if="modal" :object="object" :record="modal.record" @close="modal = null" @saved="modal = null; load()" />
    </template>
    <p v-else class="text-sm text-slate-400">Loading…</p>
</template>
