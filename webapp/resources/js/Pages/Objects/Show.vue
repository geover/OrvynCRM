<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Clock, Download, Filter, LayoutGrid, Link2, Pencil, Plus, Rows3, Settings, Trash2, TrendingUp, Users } from 'lucide-vue-next';
import StatTile from '@/Components/StatTile.vue';
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
import { confirmDialog, fmtDate, fmtRelative, run } from '@/lib/ui';

const props = defineProps({ objectId: Number });
const object = ref(null);
const display = useStored(`display:object-${props.objectId}`, 'table');      // table | cards
const overview = ref(null);                                                    // the numbers above the items
const spreadKey = useStored(`spread:object-${props.objectId}`, '');            // which dropdown the colour bar breaks the items down by ('' = the object's first)
async function loadOverview() { try { overview.value = (await api.get(`/data-objects/${props.objectId}/records/overview`, { field: spreadKey.value || undefined })).data; } catch { overview.value = null; } }
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
onMounted(async () => { object.value = (await api.get(`/data-objects/${props.objectId}`)).data; load(); loadOverview(); });
let t;
watch(spreadKey, loadOverview);
watch(sort, fromStart);
watch(q, () => { clearTimeout(t); t = setTimeout(fromStart, 300); });

async function remove(r) {
    if (!(await confirmDialog({ title: 'Delete this item?', message: 'It is removed for every contact it is linked to.', danger: true, confirmLabel: 'Delete' }))) return;
    await run(() => api.delete(`/data-objects/${props.objectId}/records/${r.id}`), 'Deleted'); load(); loadOverview();
}
async function removeSelected() {
    const n = selected.value.length;
    if (!(await confirmDialog({ title: `Delete ${n} item${n === 1 ? '' : 's'}?`, message: 'They are removed for every contact they are linked to.', danger: true, confirmLabel: `Delete ${n}` }))) return;
    const ok = await run(async () => { for (const id of selected.value) await api.delete(`/data-objects/${props.objectId}/records/${id}`); }, `${n} deleted`);
    if (ok) { load(); loadOverview(); }
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

// every choice of a dropdown has a colour of its own, by its place in the field's list, so the bar above, the badges in the table and the cards agree
const PALETTE = ['#6366f1', '#10b981', '#f59e0b', '#0ea5e9', '#ec4899', '#8b5cf6', '#14b8a6', '#f97316', '#84cc16', '#e11d48', '#06b6d4', '#a855f7'];
const choiceColor = (f, v) => { const i = (f?.options?.choices ?? []).indexOf(v); return i < 0 ? '#cbd5e1' : PALETTE[i % PALETTE.length]; };
const empty = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length);
const num = (v) => (Number.isFinite(Number(v)) ? Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }) : v);
const spreadField = computed(() => object.value?.fields.find((f) => f.key === overview.value?.spread?.key));
const accent = (r) => (spreadField.value ? choiceColor(spreadField.value, r.data?.[spreadField.value.key]) : '#cbd5e1');
const share = (n) => (overview.value?.total ? Math.round((n / overview.value.total) * 100) : 0);
// clicking a value of the bar shows only those items; clicking it again shows everything
const spreadOn = (v) => activeRules.value.length === 1 && activeRules.value[0].field === overview.value.spread.key && activeRules.value[0].value === v;
function pickSpread(v) {
    filter.value = spreadOn(v) ? { match: 'all', rules: [] } : { match: 'all', rules: [{ field: overview.value.spread.key, op: '=', value: v }] };
    fromStart();
}
const cardFields = computed(() => listFields.value.slice(1, 6));
const linkedBoards = computed(() => overview.value?.boards ?? []);
</script>

<template>
    <Head :title="object?.name ?? 'Data Object'" />
    <template v-if="object">
        <PageHeader :title="object.name" :subtitle="object.description" :crumbs="[{ label: 'Data objects', href: '/objects' }, { label: object.name }]">
            <button type="button" class="btn-outline" data-testid="object-transfer" @click="transfer = true"><Download class="size-4" /> Import / export</button>
            <button type="button" class="btn-outline" data-testid="object-settings" @click="settings = true"><Settings class="size-4" /> Fields &amp; settings</button>
            <button class="btn-primary" @click="modal = { record: null }"><Plus class="size-4" /> New item</button>
        </PageHeader>

        <section v-if="overview" class="mb-5 space-y-4" data-testid="object-summary" aria-label="This data object in numbers">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatTile :label="`${object.name} items`" :value="overview.total.toLocaleString()" :hint="`${object.fields.length} fields`" />
                <StatTile label="Added this week" :value="overview.added_7d.toLocaleString()" :hint="`${overview.added_30d.toLocaleString()} in the last 30 days`">
                    <div v-if="overview.added_7d" class="flex items-center gap-1 text-xs text-emerald-600"><TrendingUp class="size-3.5" /> growing</div>
                </StatTile>
                <StatTile label="Linked contacts" :value="overview.contacts.toLocaleString()" hint="people connected to these items" />
                <StatTile label="Last change" :value="overview.last_change ? fmtRelative(overview.last_change) : '—'" :hint="overview.last_change ? fmtDate(overview.last_change, true) : 'no items yet'" />
            </div>

            <div v-if="overview.spread && overview.total" class="card p-4" data-testid="object-spread">
                <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 class="flex flex-wrap items-center gap-2 text-sm font-semibold">{{ overview.total.toLocaleString() }} items by
                        <select v-if="overview.selects.length > 1" :value="overview.spread.key" class="input !w-auto !py-1 text-sm font-semibold" aria-label="Break the items down by" data-testid="spread-field" @change="spreadKey = $event.target.value">
                            <option v-for="f in overview.selects" :key="f.key" :value="f.key">{{ f.label }}</option>
                        </select><template v-else>{{ overview.spread.label.toLowerCase() }}</template></h2><span class="text-xs text-slate-400">Click a value to show only those items</span></div>
                <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-slate-100" role="img" :aria-label="overview.spread.values.map((x) => `${x.value} ${x.count}`).join(', ')">
                    <button v-for="x in overview.spread.values.filter((y) => y.count)" :key="x.value" type="button" class="h-full transition hover:brightness-110 focus:outline-none" :style="{ width: `${(x.count / overview.total) * 100}%`, background: choiceColor(spreadField, x.value) }" :title="`${x.value}: ${x.count}`" @click="pickSpread(x.value)" />
                    <span v-if="overview.spread.empty" class="h-full bg-slate-200" :style="{ width: `${(overview.spread.empty / overview.total) * 100}%` }" :title="`No ${overview.spread.label.toLowerCase()}: ${overview.spread.empty}`" />
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button v-for="x in overview.spread.values" :key="x.value" type="button" class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition hover:bg-slate-50" :class="spreadOn(x.value) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600'" data-testid="spread-value" @click="pickSpread(x.value)">
                        <span class="size-2 rounded-full" :style="{ background: choiceColor(spreadField, x.value) }" />{{ x.value }}<span class="tabular-nums text-slate-400">{{ x.count }} · {{ share(x.count) }}%</span>
                    </button>
                </div>
            </div>

            <div v-if="linkedBoards.length || object.relationships?.length" class="flex flex-wrap items-center gap-2 text-xs">
                <template v-if="linkedBoards.length"><span class="flex items-center gap-1 text-slate-400"><Boxes class="size-3.5" /> Boards</span>
                    <Link v-for="b in linkedBoards" :key="b.id" :href="`/boards/${b.id}`" class="badge transition hover:bg-brand-50" data-testid="object-board">{{ b.name }}</Link></template>
                <template v-if="object.relationships?.length"><span class="ml-2 flex items-center gap-1 text-slate-400"><Link2 class="size-3.5" /> Contacts can be</span>
                    <span v-for="r in object.relationships" :key="r.id" class="badge">{{ r.label }}</span></template>
            </div>
        </section>

        <div class="mb-4 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <input v-model="q" class="input !w-72" :placeholder="`Search ${object.name.toLowerCase()}…`" />
                <button class="btn-outline" :class="activeRules.length && '!border-brand-500 !text-brand-700'" @click="showFilter = !showFilter"><Filter class="size-4" /> Filters<span v-if="activeRules.length" class="badge ml-1 !bg-brand-50 !text-brand-700">{{ activeRules.length }}</span></button>
                <button v-if="activeRules.length" class="btn-ghost" @click="filter = { match: 'all', rules: [] }; fromStart()">Clear</button>
                <div class="ml-auto inline-flex rounded-lg border border-slate-200 p-0.5" role="group" aria-label="Show items as">
                    <button type="button" class="rounded-md p-1.5" :class="display === 'table' ? 'bg-brand-50 text-brand-700' : 'text-slate-400 hover:text-slate-700'" title="Table" aria-label="Table" :aria-pressed="display === 'table'" data-testid="view-table" @click="display = 'table'"><Rows3 class="size-4" /></button>
                    <button type="button" class="rounded-md p-1.5" :class="display === 'cards' ? 'bg-brand-50 text-brand-700' : 'text-slate-400 hover:text-slate-700'" title="Cards" aria-label="Cards" :aria-pressed="display === 'cards'" data-testid="view-cards" @click="display = 'cards'"><LayoutGrid class="size-4" /></button>
                </div>
            </div>
            <div v-if="showFilter" class="card space-y-3 p-4">
                <FilterBuilder v-model="filter" :fields="object.fields" />
                <div class="flex justify-end"><button class="btn-primary" @click="fromStart">Apply filters</button></div>
            </div>
        </div>

        <DataTable v-model:selected="selected" v-model:sort="sort" :columns="columns" :rows="rows" :loading="loading" :display="display" selectable row-clickable noun="item" :storage-key="`object-${objectId}`" :empty-text="`No items${activeRules.length || q ? ' match your search' : ' yet'}.`" @row-click="(r) => (modal = { record: r })">
            <template v-for="f in listFields" :key="f.key" #[`cell-f:${f.key}`]="{ row: r }">
                <span v-if="f.type === 'select' && !empty(r.data[f.key])" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700"><span class="size-2 rounded-full" :style="{ background: choiceColor(f, r.data[f.key]) }" />{{ r.data[f.key] }}</span>
                <span v-else-if="f.type === 'multiselect' && !empty(r.data[f.key])" class="flex flex-wrap gap-1"><span v-for="v in r.data[f.key]" :key="v" class="badge">{{ v }}</span></span>
                <span v-else-if="f.type === 'checkbox'" :class="r.data[f.key] ? 'text-emerald-600' : 'text-slate-300'">{{ r.data[f.key] ? 'Yes' : '—' }}</span>
                <span v-else-if="f.type === 'number' && !empty(r.data[f.key])" class="font-medium tabular-nums">{{ num(r.data[f.key]) }}</span>
                <span v-else-if="f.type === 'date' && !empty(r.data[f.key])" class="text-slate-600">{{ fmtDate(r.data[f.key]) }}</span>
                <span v-else-if="f.type === 'datetime' && !empty(r.data[f.key])" class="text-slate-600">{{ fmtDate(r.data[f.key], true) }}</span>
                <a v-else-if="f.type === 'email' && !empty(r.data[f.key])" :href="`mailto:${r.data[f.key]}`" class="text-brand-700 hover:underline" @click.stop>{{ r.data[f.key] }}</a>
                <template v-else>{{ show(r.data[f.key]) }}</template>
            </template>
            <template #cell-contacts="{ row: r }"><span @click.stop><Link v-for="c in r.contacts" :key="c.id" :href="`/contacts/${c.id}`" class="badge mr-1 hover:bg-brand-50">{{ c.name }}</Link></span></template>
            <template #bulk><button class="btn-outline !py-1.5" data-testid="items-export" @click="exportSelected"><Download class="size-4" /> Export CSV</button><button class="btn-outline !py-1.5 !text-red-600 hover:!bg-red-50" @click="removeSelected"><Trash2 class="size-4" /> Delete</button></template>
            <template #actions="{ row: r }"><button class="btn-ghost !px-1.5" title="Edit" @click="modal = { record: r }"><Pencil class="size-4" /></button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" @click="remove(r)"><Trash2 class="size-4" /></button></template>
            <template #card="{ row: r, selected: on, toggle }">
                <article class="group relative flex cursor-pointer flex-col overflow-hidden rounded-2xl border bg-white transition hover:-translate-y-0.5 hover:shadow-md" :class="on ? 'border-brand-500 ring-1 ring-brand-500' : 'border-slate-200'" data-testid="item-card" @click="modal = { record: r }">
                    <div class="h-1.5" :style="{ background: accent(r) }" />
                    <div class="flex items-start gap-3 p-4 pb-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold">{{ empty(r.data[listFields[0]?.key]) ? `#${r.id}` : show(r.data[listFields[0].key]) }}</div>
                            <div class="mt-0.5 flex items-center gap-1 text-xs text-slate-400"><Clock class="size-3" /> {{ fmtRelative(r.updated_at) }}</div>
                        </div>
                        <input type="checkbox" class="mt-1 rounded" :checked="on" :aria-label="`Select ${object.name}`" @click.stop @change="toggle" />
                    </div>
                    <dl class="space-y-1.5 px-4 pb-3 text-sm">
                        <div v-for="f in cardFields.filter((x) => !empty(r.data[x.key]))" :key="f.key" class="flex items-baseline justify-between gap-3">
                            <dt class="shrink-0 text-xs text-slate-400">{{ f.label }}</dt>
                            <dd class="min-w-0 truncate text-right text-slate-700" :title="show(r.data[f.key])">
                                <span v-if="f.type === 'select'" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium"><span class="size-2 rounded-full" :style="{ background: choiceColor(f, r.data[f.key]) }" />{{ r.data[f.key] }}</span>
                                <span v-else-if="f.type === 'number'" class="font-semibold tabular-nums">{{ num(r.data[f.key]) }}</span>
                                <template v-else-if="f.type === 'date'">{{ fmtDate(r.data[f.key]) }}</template>
                                <template v-else>{{ show(r.data[f.key]) }}</template>
                            </dd>
                        </div>
                    </dl>
                    <div class="mt-auto flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5">
                        <span class="flex min-w-0 flex-wrap items-center gap-1" @click.stop>
                            <Link v-for="c in (r.contacts ?? []).slice(0, 2)" :key="c.id" :href="`/contacts/${c.id}`" class="badge max-w-[8rem] hover:bg-brand-50"><Users class="mr-1 size-3 shrink-0" /><span class="truncate">{{ c.name }}</span></Link>
                            <span v-if="(r.contacts ?? []).length > 2" class="badge">+{{ r.contacts.length - 2 }}</span>
                            <span v-if="!(r.contacts ?? []).length" class="text-xs text-slate-300">No contacts</span>
                        </span>
                        <span class="inline-flex shrink-0 gap-0.5 opacity-60 transition group-hover:opacity-100" @click.stop>
                            <button class="btn-ghost !px-1.5 !py-1" title="Edit" aria-label="Edit" @click="modal = { record: r }"><Pencil class="size-4" /></button>
                            <button class="btn-ghost !px-1.5 !py-1 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete" @click="remove(r)"><Trash2 class="size-4" /></button>
                        </span>
                    </div>
                </article>
            </template>
            <template #footer><TablePagination :meta="meta" :loading="loading" @page="goTo" @size="resize" /></template>
        </DataTable>

        <Modal v-if="transfer" :title="`${object.name}: import & export`" width="max-w-5xl" @close="transfer = false">
            <DataTransfer :object-id="objectId" @finished="load" />
        </Modal>

        <Modal v-if="settings" :title="`${object.name}: fields & settings`" width="max-w-5xl" @close="settings = false">
            <ObjectEdit :object-id="objectId" embedded @saved="settingsSaved" @deleted="router.visit('/objects')" />
        </Modal>

        <RecordModal v-if="modal" :object="object" :record="modal.record" @close="modal = null" @saved="modal = null; load(); loadOverview()" />
    </template>
    <p v-else class="text-sm text-slate-400">Loading…</p>
</template>
