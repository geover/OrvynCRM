<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Download, Upload } from 'lucide-vue-next';
import { api } from '@/lib/api';
import Modal from '@/Components/Modal.vue';
import { fmtDate, toast } from '@/lib/ui';

// Import and export with CSV, for the whole company (the Data import & export page) or for one data object (`objectId`: the pop-up on the object's page).
const props = defineProps({ objectId: { type: Number, default: null } });
const emit = defineEmits(['finished']);          // a job that was running has finished: the page behind may want to reload its list

const tab = ref('import');
const objects = ref([]);
const transfers = ref([]);
let poll;

// ---- import wizard
const imp = ref({ resource: props.objectId ? 'data_objects' : 'contacts', data_object_id: props.objectId ?? '', identifying_field: '', relationship_id: '' });
const upload = ref(null);          // {path,name}
const preview = ref(null);
const mapping = ref({});
const busy = ref(false);
// ---- export
const exp = ref({ resource: props.objectId ? 'data_objects' : 'contacts', data_object_id: props.objectId ?? '', status: 'all' });

const object = () => objects.value.find((o) => o.id === Number(imp.value.data_object_id));
async function load() {
    const before = transfers.value;
    transfers.value = (await api.get('/transfers')).data;
    if (before.some((b) => ['pending', 'running'].includes(b.status) && transfers.value.some((t) => t.id === b.id && !['pending', 'running'].includes(t.status)))) emit('finished');
}
const detail = ref(null);          // the job whose details are open
const objectName = (t) => objects.value.find((o) => o.id === Number(t.options?.data_object_id))?.name;
const what = (t) => (t.resource === 'data_objects' ? `Items of ${objectName(t) ?? 'a data object'}` : t.resource === 'notes_tasks' ? 'Notes and tasks' : 'Contacts');
const errorLines = (t) => (t.error ?? '').split('\n').filter(Boolean);
const mapped = (t) => Object.entries(t.options?.mapping ?? {}).filter(([, to]) => to);
const fieldLabel = (t, key) => (key === '_contact_email' ? 'Link to contact by e-mail' : objects.value.find((o) => o.id === Number(t.options?.data_object_id))?.fields?.find((f) => f.key === key)?.label ?? key.replace(/_/g, ' '));
const shown = computed(() => (props.objectId ? transfers.value.filter((t) => Number(t.options?.data_object_id) === props.objectId) : transfers.value));
onMounted(async () => { objects.value = (await api.get('/data-objects')).data; load(); poll = setInterval(() => transfers.value.some((t) => ['pending', 'running'].includes(t.status)) && load(), 3000); });
onBeforeUnmount(() => clearInterval(poll));

// ---- the CSV an import needs, made from the current setup (so it always has this company's own fields)
const CONTACT_COLUMNS = [
    { label: 'First Name', format: 'Text' }, { label: 'Middle Name', format: 'Text' }, { label: 'Last Name', format: 'Text' }, { label: 'Title', format: 'Text, such as Mr or Ms' },
    { label: 'Email', required: true, format: 'E-mail address. A contact with the same address is updated, not duplicated' },
    { label: 'Phone', format: 'Text' }, { label: 'Company Name', format: 'Text' }, { label: 'Job Title', format: 'Text' }, { label: 'Website', format: 'https://…' },
    { label: 'Birthday', format: 'YYYY-MM-DD' }, { label: 'Contact Type', format: 'Text' }, { label: 'Tags', format: 'Names separated by commas, such as vip, newsletter' }, { label: 'Notes', format: 'Text' },
];
const typeFormat = (f) => ({
    text: 'Text', textarea: 'Text', number: 'A number, such as 1250.50', date: 'YYYY-MM-DD', datetime: 'YYYY-MM-DD HH:MM', email: 'E-mail address', url: 'https://…',
    select: `One of: ${(f.options?.choices ?? []).join(', ') || 'your choices'}`, multiselect: `Any of: ${(f.options?.choices ?? []).join(', ') || 'your choices'}, separated by ;`,
    checkbox: 'Yes or No (1 / 0, true / false also work)',
})[f.type] ?? 'Text';
const templateColumns = computed(() => {
    if (imp.value.resource === 'contacts') return CONTACT_COLUMNS;
    const o = object();
    if (!o) return [];
    return [
        ...o.fields.filter((f) => f.type !== 'file').map((f) => ({ label: f.label, required: !!f.is_required, format: typeFormat(f) })),
        ...(o.relationships?.length ? [{ label: 'Link to contact by e-mail', format: 'The e-mail of a contact that already exists; choose how they are linked when you import' }] : []),
    ];
});
const csvCell = (v) => (/[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
function downloadTemplate() {
    const name = imp.value.resource === 'contacts' ? 'contacts' : object().name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([`﻿${templateColumns.value.map((c) => csvCell(c.label)).join(',')}\n`], { type: 'text/csv;charset=utf-8' }));
    a.download = `${name}-import-template.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}

// ---- the contents of the file as a table, read a screenful at a time (a big file is never loaded whole)
const view = ref({ open: false, rows: [], cursor: null, done: false, loading: false });
const scroller = ref(null);
const sentinel = ref(null);
let watcher = null;
const resetView = () => { view.value = { open: false, rows: [], cursor: null, done: false, loading: false }; watcher?.disconnect(); };
async function moreRows() {
    const v = view.value;
    if (v.loading || v.done || !upload.value) return;
    v.loading = true;
    try {
        const r = await api.post('/transfers/rows', { path: upload.value.path, cursor: v.cursor || undefined, limit: 50 });
        v.rows.push(...r.rows);
        v.cursor = r.cursor;
        v.done = r.cursor === null;
    } catch (e) { toast(e.message, 'error'); v.done = true; } finally { v.loading = false; }
}
async function togglePreview(e) {
    view.value.open = e.target.open;
    if (!view.value.open) return;
    await moreRows();
    await nextTick();
    // when the end of the list scrolls into view, the next screenful is fetched
    watcher?.disconnect();
    if (sentinel.value && scroller.value) {
        watcher = new IntersectionObserver((entries) => entries.some((x) => x.isIntersecting) && moreRows(), { root: scroller.value, rootMargin: '200px' });
        watcher.observe(sentinel.value);
    }
}
onBeforeUnmount(() => watcher?.disconnect());

async function pick(e) {
    const file = e.target.files[0]; if (!file) return;
    busy.value = true;
    const fd = new FormData(); fd.append('file', file);
    const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/api/v1/uploads', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
    const d = await r.json(); busy.value = false;
    if (!r.ok) return toast(d.errors?.file?.[0] ?? d.message ?? 'Upload failed', 'error');
    upload.value = d; resetView(); await analyse();
}
async function analyse() {
    if (!upload.value) return;
    try { preview.value = await api.post('/transfers/preview', { path: upload.value.path, resource: imp.value.resource, data_object_id: imp.value.data_object_id || null }); mapping.value = { ...preview.value.mapping }; }
    catch (e) { toast(e.message, 'error'); }
}
watch(() => [imp.value.resource, imp.value.data_object_id], analyse);

async function runImport() {
    busy.value = true;
    try {
        await api.post('/transfers', { direction: 'import', resource: imp.value.resource, path: upload.value.path, options: { mapping: mapping.value, data_object_id: imp.value.data_object_id || null, identifying_field: imp.value.identifying_field || null, relationship_id: imp.value.relationship_id || null } });
        toast('Import started'); upload.value = preview.value = null; resetView(); load();
    } catch (e) { toast(Object.values(e.errors ?? {})[0]?.[0] ?? e.message, 'error'); } finally { busy.value = false; }
}
async function runExport() {
    try { await api.post('/transfers', { direction: 'export', resource: exp.value.resource, options: { data_object_id: exp.value.data_object_id || null, status: exp.value.status } }); toast('Export started'); load(); }
    catch (e) { toast(Object.values(e.errors ?? {})[0]?.[0] ?? e.message, 'error'); }
}
const pct = (t) => (t.total ? Math.round((100 * (t.processed + t.failed)) / t.total) : t.status === 'done' ? 100 : 0);
const stColor = { done: '!bg-emerald-50 !text-emerald-700', running: '!bg-blue-50 !text-blue-700', failed: '!bg-red-50 !text-red-700' };
</script>

<template>
    <div>
        <div class="mb-4 flex w-fit rounded-lg bg-slate-100 p-1 text-sm">
            <button :class="tab === 'import' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'import'">Import</button>
            <button :class="tab === 'export' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'export'">Export</button>
        </div>

        <div class="grid max-w-5xl grid-cols-[minmax(0,1fr)] gap-6">
            <section v-if="tab === 'import'" class="card min-w-0 space-y-4 p-5">
                <h2 class="text-sm font-semibold">Import from CSV</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div v-if="!objectId"><label class="label">Import type</label><select v-model="imp.resource" class="input"><option value="contacts">Contacts</option><option value="data_objects">Data objects</option></select></div>
                    <div v-if="imp.resource === 'data_objects' && !objectId"><label class="label">Data object type</label><select v-model="imp.data_object_id" class="input"><option value="" disabled>Choose…</option><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                    <div v-if="imp.resource === 'data_objects' && object()"><label class="label">Identifying field</label><select v-model="imp.identifying_field" class="input"><option value="">None — always create new</option><option v-for="f in object().fields" :key="f.key" :value="f.key">{{ f.label }}</option></select>
                        <p class="mt-1 text-xs text-slate-400">Matches existing items to avoid duplicates.</p></div>
                </div>
                <details v-if="templateColumns.length" class="rounded-lg border border-slate-200 text-sm" data-testid="import-template">
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3"><span class="font-medium">The columns an import needs <span class="text-slate-400">({{ templateColumns.length }})</span></span>
                        <button type="button" class="btn-outline !py-1.5" data-testid="template-download" @click.prevent="downloadTemplate"><Download class="size-4" /> Download CSV template</button></summary>
                    <div class="overflow-x-auto border-t border-slate-100"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2">Column</th><th class="px-4 py-2">What goes in it</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="c in templateColumns" :key="c.label"><td class="whitespace-nowrap px-4 py-2 font-medium">{{ c.label }}<span v-if="c.required" class="ml-1 text-red-600" title="Required">*</span></td><td class="px-4 py-2 text-slate-600">{{ c.format }}</td></tr></tbody></table></div>
                    <p class="border-t border-slate-100 px-4 py-2 text-xs text-slate-400">The template has the column names only, made from your current fields, so it is always up to date. Fill in one row per {{ imp.resource === 'contacts' ? 'contact' : 'item' }}; columns you leave out are simply skipped. * required.</p>
                </details>
                <label class="btn-outline cursor-pointer" :class="(imp.resource === 'data_objects' && !imp.data_object_id) && 'pointer-events-none opacity-50'"><Upload class="size-4" /> {{ upload ? upload.name : 'Choose CSV file' }}<input type="file" accept=".csv,text/csv" class="hidden" @change="pick" /></label>

                <div v-if="preview" class="min-w-0 space-y-4">
                    <p class="text-sm text-slate-600"><b>{{ preview.total_rows.toLocaleString() }}</b> rows found. Match each column to a field:</p>
                    <details class="min-w-0 max-w-full rounded-lg border border-slate-200 text-sm" data-testid="csv-preview" @toggle="togglePreview">
                        <summary class="cursor-pointer px-4 py-3 font-medium">Preview the file <span class="text-slate-400">({{ preview.total_rows.toLocaleString() }} rows)</span></summary>
                        <div ref="scroller" class="max-h-[45vh] overflow-auto border-t border-slate-100">
                            <table class="w-full text-left text-xs">
                                <thead class="sticky top-0 bg-slate-50 uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-2 text-right text-slate-400">#</th><th v-for="h in preview.headers" :key="h" class="whitespace-nowrap px-3 py-2">{{ h }}</th></tr></thead>
                                <tbody class="divide-y divide-slate-100"><tr v-for="(r, i) in view.rows" :key="i" class="hover:bg-slate-50"><td class="px-3 py-1.5 text-right text-slate-400">{{ i + 1 }}</td><td v-for="(h, j) in preview.headers" :key="j" class="max-w-56 truncate px-3 py-1.5" :title="r[j]">{{ r[j] }}</td></tr></tbody>
                            </table>
                            <div ref="sentinel" class="px-4 py-3 text-center text-xs text-slate-400">{{ view.loading ? 'Loading…' : view.done ? (view.rows.length ? `All ${view.rows.length.toLocaleString()} rows are shown.` : 'The file has no rows.') : 'Scroll for more' }}</div>
                        </div>
                    </details>
                    <div class="max-h-[45vh] overflow-auto rounded-lg border border-slate-200" data-testid="mapping-table"><table class="w-full text-sm"><thead class="sticky top-0 z-10 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-2">CSV column</th><th class="px-3 py-2">Sample</th><th class="px-3 py-2">Import into</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="(h, i) in preview.headers" :key="h"><td class="px-3 py-2 font-medium">{{ h }}</td><td class="max-w-48 truncate px-3 py-2 text-slate-500">{{ preview.sample[0]?.[i] }}</td>
                            <td class="px-3 py-2"><select v-model="mapping[h]" class="input !py-1"><option value="">— skip —</option><option v-for="t in preview.targets" :key="t.key" :value="t.key">{{ t.label }}</option></select></td></tr></tbody></table></div>
                    <div v-if="Object.values(mapping).includes('_contact_email') && object()"><label class="label">Link contacts as</label><select v-model="imp.relationship_id" class="input !w-64"><option value="" disabled>Choose…</option><option v-for="r in object().relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                    <button class="btn-primary" :disabled="busy" @click="runImport">Start import</button>
                </div>
            </section>

            <section v-if="tab === 'export'" class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Export to CSV</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div v-if="!objectId"><label class="label">Data export type</label><select v-model="exp.resource" class="input"><option value="contacts">All contacts</option><option value="notes_tasks">Notes &amp; tasks</option><option value="data_objects">Data objects</option></select></div>
                    <div v-if="exp.resource === 'data_objects' && !objectId"><label class="label">Data object type</label><select v-model="exp.data_object_id" class="input"><option value="" disabled>Choose…</option><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                    <div v-if="exp.resource === 'data_objects'"><label class="label">Include</label><select v-model="exp.status" class="input"><option value="all">Active &amp; inactive</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
                <button class="btn-primary" :disabled="exp.resource === 'data_objects' && !exp.data_object_id" @click="runExport"><Download class="size-4" /> Export</button>
            </section>

            <section class="card">
                <h2 class="card-title">Recent jobs</h2>
                <div class="max-h-[45vh] divide-y divide-slate-100 overflow-y-auto">
                    <div v-for="t in shown" :key="t.id" class="px-5 py-3 text-sm">
                        <div class="flex items-center justify-between gap-3"><button type="button" class="text-left font-medium capitalize hover:text-brand-700 hover:underline" data-testid="job-open" @click="detail = t">{{ t.direction }} · {{ t.resource.replace('_', ' ') }}</button>
                            <div class="flex items-center gap-2"><span class="badge" :class="stColor[t.status]">{{ t.status }}</span><a v-if="t.direction === 'export' && t.status === 'done'" :href="`/files/transfers/${t.id}`" class="btn-outline !py-1">Download</a></div></div>
                        <div class="mt-1 text-xs text-slate-400">{{ fmtDate(t.created_at, true) }} · {{ t.processed.toLocaleString() }} processed<span v-if="t.failed"> · <span class="text-red-600">{{ t.failed }} failed</span></span></div>
                        <div v-if="t.status === 'running'" class="mt-2 h-1.5 overflow-hidden rounded bg-slate-100"><div class="h-full bg-brand-600 transition-all" :style="{ width: pct(t) + '%' }" /></div>
                        <pre v-if="t.error" class="mt-2 max-h-32 overflow-auto rounded bg-red-50 p-2 text-xs text-red-800">{{ t.error }}</pre>
                    </div>
                    <p v-if="!shown.length" class="px-5 py-8 text-center text-sm text-slate-400">Nothing yet.</p>
                </div>
            </section>
        </div>

        <Modal v-if="detail" :title="`${detail.direction === 'import' ? 'Import' : 'Export'} · ${what(detail)}`" width="max-w-2xl" @close="detail = null">
            <div class="space-y-4 text-sm" data-testid="job-detail">
                <div class="flex flex-wrap items-center gap-2"><span class="badge" :class="stColor[detail.status]">{{ detail.status }}</span>
                    <a v-if="detail.direction === 'export' && detail.status === 'done'" :href="`/files/transfers/${detail.id}`" class="btn-outline !py-1"><Download class="size-4" /> Download the CSV</a></div>
                <div v-if="detail.status === 'running'" class="h-1.5 overflow-hidden rounded bg-slate-100"><div class="h-full bg-brand-600 transition-all" :style="{ width: pct(detail) + '%' }" /></div>

                <dl class="grid gap-x-6 gap-y-2 text-slate-600 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Started by</dt><dd>{{ detail.user?.name ?? 'Unknown' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Requested</dt><dd>{{ fmtDate(detail.created_at, true) }}</dd></div>
                    <div v-if="['done', 'failed'].includes(detail.status)"><dt class="text-xs uppercase tracking-wide text-slate-400">Finished</dt><dd>{{ fmtDate(detail.updated_at, true) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Rows done</dt><dd>{{ detail.processed.toLocaleString() }}<span v-if="detail.total"> of {{ detail.total.toLocaleString() }}</span></dd></div>
                    <div v-if="detail.failed"><dt class="text-xs uppercase tracking-wide text-slate-400">Rows that failed</dt><dd class="text-red-600">{{ detail.failed.toLocaleString() }}</dd></div>
                    <div v-if="detail.direction === 'export' && detail.options?.status && detail.resource === 'data_objects'"><dt class="text-xs uppercase tracking-wide text-slate-400">Included</dt><dd class="capitalize">{{ detail.options.status === 'all' ? 'Active and inactive' : detail.options.status }}</dd></div>
                    <div v-if="detail.options?.identifying_field"><dt class="text-xs uppercase tracking-wide text-slate-400">Matched existing items by</dt><dd>{{ fieldLabel(detail, detail.options.identifying_field) }}</dd></div>
                </dl>

                <div v-if="detail.direction === 'import' && mapped(detail).length">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">How the columns were matched</h3>
                    <div class="overflow-x-auto rounded-lg border border-slate-200"><table class="w-full text-left"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-2">CSV column</th><th class="px-3 py-2">Imported into</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="[from, to] in mapped(detail)" :key="from"><td class="px-3 py-1.5 font-medium">{{ from }}</td><td class="px-3 py-1.5 text-slate-600">{{ detail.resource === 'contacts' ? to.replace(/_/g, ' ') : fieldLabel(detail, to) }}</td></tr></tbody></table></div>
                </div>

                <div v-if="errorLines(detail).length">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ detail.status === 'failed' ? 'Why it stopped' : 'Rows that could not be imported' }}</h3>
                    <ul class="max-h-56 space-y-1 overflow-auto rounded-lg bg-red-50 p-3 text-xs text-red-800" data-testid="job-errors"><li v-for="(line, i) in errorLines(detail)" :key="i">{{ line }}</li></ul>
                    <p v-if="detail.failed > errorLines(detail).length" class="mt-1 text-xs text-slate-400">The first {{ errorLines(detail).length }} problems are kept; {{ detail.failed.toLocaleString() }} rows failed in all.</p>
                </div>
            </div>
        </Modal>
    </div>
</template>
