<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Filter, Pencil, Plus, Settings, Trash2 } from 'lucide-vue-next';
import DataTable from '@/Components/DataTable.vue';
import FilterBuilder from '@/Components/FilterBuilder.vue';
import Modal from '@/Components/Modal.vue';
import ObjectEdit from './Edit.vue';
import DataTransfer from '@/Components/DataTransfer.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TablePagination from '@/Components/TablePagination.vue';
import RecordModal from '@/Components/RecordModal.vue';
import { api } from '@/lib/api';
import { useStored } from '@/lib/stored';
import { confirmDialog, run } from '@/lib/ui';

const props = defineProps({ objectId: Number });
const object = ref(null);
const rows = ref([]);
const meta = ref(null);            // the page being shown: {current_page, last_page, per_page, total, from, to}
const page = ref(1);
const perPage = useStored('perpage:objects', 25);
const q = ref('');
const filter = ref({ match: 'all', rules: [] });
const showFilter = ref(false);
const modal = ref(null);          // {record|null}
const transfer = ref(false);      // import / export of this object's items, in a wide pop-up
const settings = ref(false);      // the fields and settings of this object, in a wide pop-up
async function settingsSaved() {
    settings.value = false;
    object.value = (await api.get(`/data-objects/${props.objectId}`)).data;           // the fields may have changed: the columns follow
    load();
}
const loading = ref(true);
const selected = ref([]);
const sort = ref('');

const listFields = computed(() => {
    const f = object.value?.fields.filter((x) => x.show_in_list) ?? [];
    return f.length ? f : (object.value?.fields.slice(0, 5) ?? []);
});
const columns = computed(() => [...listFields.value.map((f, i) => ({ key: `f:${f.key}`, label: f.label, sort: f.key, title: i === 0 })), { key: 'contacts', label: 'Contacts' }]);
const activeRules = computed(() => (filter.value.rules ?? []).filter((r) => r.rules || (r.field && (['empty', 'not_empty'].includes(r.op) || (r.value !== '' && r.value !== null)))));

async function load() {
    loading.value = true;
    const params = { q: q.value, sort: sort.value || undefined, page: page.value, per_page: perPage.value };
    if (activeRules.value.length) { params['filter[match]'] = filter.value.match; activeRules.value.forEach((r, i) => { if (!r.rules) { params[`filter[rules][${i}][field]`] = r.field; params[`filter[rules][${i}][op]`] = r.op; const v = r.value; Array.isArray(v) ? v.forEach((x, j) => (params[`filter[rules][${i}][value][${j}]`] = x)) : (params[`filter[rules][${i}][value]`] = v); } }); }
    try {
        const res = await api.get(`/data-objects/${props.objectId}/records`, params);
        if (res.meta?.last_page && page.value > res.meta.last_page) { page.value = res.meta.last_page; return load(); }       // the last page emptied (deleted items)
        rows.value = res.data;
        meta.value = res.meta;
        selected.value = [];
    } finally { loading.value = false; }
}
const goTo = (n) => { page.value = n; load(); window.scrollTo({ top: 0 }); };
const resize = (n) => { perPage.value = n; goTo(1); };
const fromStart = () => { page.value = 1; load(); };           // a new search, filter or order starts on page 1
onMounted(async () => { object.value = (await api.get(`/data-objects/${props.objectId}`)).data; load(); });
let t;
watch(sort, fromStart);
watch(q, () => { clearTimeout(t); t = setTimeout(fromStart, 300); });

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
// the ticked items as a CSV: every field of the object (not only the columns shown) and the contacts linked to each
const csvCell = (v) => {
    let t = Array.isArray(v) ? v.join('; ') : typeof v === 'boolean' ? (v ? 'Yes' : 'No') : (v ?? '');
    t = String(t);
    if (/^[=+\-@\t\r]/.test(t)) t = `'${t}`;                    // a leading = + - @ would run as a formula in a spreadsheet
    return /[",\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
};
function exportSelected() {
    const pick = rows.value.filter((r) => selected.value.includes(r.id));
    const fields = object.value.fields;
    const lines = [
        [...fields.map((f) => f.label), 'Contacts'].map(csvCell).join(','),
        ...pick.map((r) => [...fields.map((f) => r.data?.[f.key]), (r.contacts ?? []).map((c) => c.name).join('; ')].map(csvCell).join(',')),
    ];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([`﻿${lines.join('\n')}`], { type: 'text/csv;charset=utf-8' }));
    a.download = `${object.value.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}
const show = (v) => (Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? 'Yes' : 'No') : v);
</script>

<template>
    <Head :title="object?.name ?? 'Data Object'" />
    <template v-if="object">
        <PageHeader :title="object.name" :subtitle="object.description" :crumbs="[{ label: 'Data objects', href: '/objects' }, { label: object.name }]">
            <button type="button" class="btn-outline" data-testid="object-transfer" @click="transfer = true"><Download class="size-4" /> Import / export</button>
            <button type="button" class="btn-outline" data-testid="object-settings" @click="settings = true"><Settings class="size-4" /> Fields &amp; settings</button>
            <button class="btn-primary" @click="modal = { record: null }"><Plus class="size-4" /> New item</button>
        </PageHeader>

        <div class="mb-4 space-y-3">
            <div class="flex flex-wrap gap-2">
                <input v-model="q" class="input !w-72" :placeholder="`Search ${object.name.toLowerCase()}…`" />
                <button class="btn-outline" :class="activeRules.length && '!border-brand-500 !text-brand-700'" @click="showFilter = !showFilter"><Filter class="size-4" /> Filters<span v-if="activeRules.length" class="badge ml-1 !bg-brand-50 !text-brand-700">{{ activeRules.length }}</span></button>
                <button v-if="activeRules.length" class="btn-ghost" @click="filter = { match: 'all', rules: [] }; fromStart()">Clear</button>
            </div>
            <div v-if="showFilter" class="card space-y-3 p-4">
                <FilterBuilder v-model="filter" :fields="object.fields" />
                <div class="flex justify-end"><button class="btn-primary" @click="fromStart">Apply filters</button></div>
            </div>
        </div>

        <DataTable v-model:selected="selected" v-model:sort="sort" :columns="columns" :rows="rows" :loading="loading" selectable row-clickable noun="item" :storage-key="`object-${objectId}`" :empty-text="`No items${activeRules.length || q ? ' match your search' : ' yet'}.`" @row-click="(r) => (modal = { record: r })">
            <template v-for="f in listFields" :key="f.key" #[`cell-f:${f.key}`]="{ row: r }">{{ show(r.data[f.key]) }}</template>
            <template #cell-contacts="{ row: r }"><span @click.stop><Link v-for="c in r.contacts" :key="c.id" :href="`/contacts/${c.id}`" class="badge mr-1 hover:bg-brand-50">{{ c.name }}</Link></span></template>
            <template #bulk><button class="btn-outline !py-1.5" data-testid="items-export" @click="exportSelected"><Download class="size-4" /> Export CSV</button><button class="btn-outline !py-1.5 !text-red-600 hover:!bg-red-50" @click="removeSelected"><Trash2 class="size-4" /> Delete</button></template>
            <template #actions="{ row: r }"><button class="btn-ghost !px-1.5" title="Edit" @click="modal = { record: r }"><Pencil class="size-4" /></button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" @click="remove(r)"><Trash2 class="size-4" /></button></template>
            <template #footer><TablePagination :meta="meta" :loading="loading" @page="goTo" @size="resize" /></template>
        </DataTable>

        <Modal v-if="transfer" :title="`${object.name}: import & export`" width="max-w-5xl" @close="transfer = false">
            <DataTransfer :object-id="objectId" @finished="load" />
        </Modal>

        <Modal v-if="settings" :title="`${object.name}: fields & settings`" width="max-w-5xl" @close="settings = false">
            <ObjectEdit :object-id="objectId" embedded @saved="settingsSaved" @deleted="router.visit('/objects')" />
        </Modal>

        <RecordModal v-if="modal" :object="object" :record="modal.record" @close="modal = null" @saved="modal = null; load()" />
    </template>
    <p v-else class="text-sm text-slate-400">Loading…</p>
</template>
