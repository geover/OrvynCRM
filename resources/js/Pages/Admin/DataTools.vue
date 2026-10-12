<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Download, Upload } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { fmtDate, toast } from '@/lib/ui';

const tab = ref('import');
const objects = ref([]);
const transfers = ref([]);
let poll;

// ---- import wizard
const imp = ref({ resource: 'contacts', data_object_id: '', identifying_field: '', relationship_id: '' });
const upload = ref(null);          // {path,name}
const preview = ref(null);
const mapping = ref({});
const busy = ref(false);
// ---- export
const exp = ref({ resource: 'contacts', data_object_id: '', status: 'all' });

const object = () => objects.value.find((o) => o.id === Number(imp.value.data_object_id));
async function load() { transfers.value = (await api.get('/transfers')).data; }
onMounted(async () => { objects.value = (await api.get('/data-objects')).data; load(); poll = setInterval(() => transfers.value.some((t) => ['pending', 'running'].includes(t.status)) && load(), 3000); });
onBeforeUnmount(() => clearInterval(poll));

async function pick(e) {
    const file = e.target.files[0]; if (!file) return;
    busy.value = true;
    const fd = new FormData(); fd.append('file', file);
    const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/api/v1/uploads', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
    const d = await r.json(); busy.value = false;
    if (!r.ok) return toast(d.errors?.file?.[0] ?? d.message ?? 'Upload failed', 'error');
    upload.value = d; await analyse();
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
        toast('Import started'); upload.value = preview.value = null; load();
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
    <AdminPage title="Data import & export" subtitle="Import new data or export existing data. Large files are processed in the background.">
        <div class="mb-4 flex w-fit rounded-lg bg-slate-100 p-1 text-sm">
            <button :class="tab === 'import' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'import'">Import</button>
            <button :class="tab === 'export' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'export'">Export</button>
        </div>

        <div class="grid max-w-5xl gap-6">
            <section v-if="tab === 'import'" class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Import from CSV</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><label class="label">Import type</label><select v-model="imp.resource" class="input"><option value="contacts">Contacts</option><option value="data_objects">Data objects</option></select></div>
                    <div v-if="imp.resource === 'data_objects'"><label class="label">Data object type</label><select v-model="imp.data_object_id" class="input"><option value="" disabled>Choose…</option><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                    <div v-if="imp.resource === 'data_objects' && object()"><label class="label">Identifying field</label><select v-model="imp.identifying_field" class="input"><option value="">None — always create new</option><option v-for="f in object().fields" :key="f.key" :value="f.key">{{ f.label }}</option></select>
                        <p class="mt-1 text-xs text-slate-400">Matches existing items to avoid duplicates.</p></div>
                </div>
                <label class="btn-outline cursor-pointer" :class="(imp.resource === 'data_objects' && !imp.data_object_id) && 'pointer-events-none opacity-50'"><Upload class="size-4" /> {{ upload ? upload.name : 'Choose CSV file' }}<input type="file" accept=".csv,text/csv" class="hidden" @change="pick" /></label>

                <div v-if="preview" class="space-y-4">
                    <p class="text-sm text-slate-600"><b>{{ preview.total_rows.toLocaleString() }}</b> rows found. Match each column to a field:</p>
                    <div class="overflow-x-auto rounded-lg border border-slate-200"><table class="w-full text-sm"><thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-2">CSV column</th><th class="px-3 py-2">Sample</th><th class="px-3 py-2">Import into</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="(h, i) in preview.headers" :key="h"><td class="px-3 py-2 font-medium">{{ h }}</td><td class="max-w-48 truncate px-3 py-2 text-slate-500">{{ preview.sample[0]?.[i] }}</td>
                            <td class="px-3 py-2"><select v-model="mapping[h]" class="input !py-1"><option value="">— skip —</option><option v-for="t in preview.targets" :key="t.key" :value="t.key">{{ t.label }}</option></select></td></tr></tbody></table></div>
                    <div v-if="Object.values(mapping).includes('_contact_email') && object()"><label class="label">Link contacts as</label><select v-model="imp.relationship_id" class="input !w-64"><option value="" disabled>Choose…</option><option v-for="r in object().relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                    <button class="btn-primary" :disabled="busy" @click="runImport">Start import</button>
                </div>
            </section>

            <section v-if="tab === 'export'" class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Export to CSV</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><label class="label">Data export type</label><select v-model="exp.resource" class="input"><option value="contacts">All contacts</option><option value="notes_tasks">Notes &amp; tasks</option><option value="data_objects">Data objects</option></select></div>
                    <div v-if="exp.resource === 'data_objects'"><label class="label">Data object type</label><select v-model="exp.data_object_id" class="input"><option value="" disabled>Choose…</option><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                    <div v-if="exp.resource === 'data_objects'"><label class="label">Include</label><select v-model="exp.status" class="input"><option value="all">Active &amp; inactive</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
                <button class="btn-primary" :disabled="exp.resource === 'data_objects' && !exp.data_object_id" @click="runExport"><Download class="size-4" /> Export</button>
            </section>

            <section class="card">
                <h2 class="card-title">Recent jobs</h2>
                <div class="divide-y divide-slate-100">
                    <div v-for="t in transfers" :key="t.id" class="px-5 py-3 text-sm">
                        <div class="flex items-center justify-between gap-3"><div class="font-medium capitalize">{{ t.direction }} · {{ t.resource.replace('_', ' ') }}</div>
                            <div class="flex items-center gap-2"><span class="badge" :class="stColor[t.status]">{{ t.status }}</span><a v-if="t.direction === 'export' && t.status === 'done'" :href="`/files/transfers/${t.id}`" class="btn-outline !py-1">Download</a></div></div>
                        <div class="mt-1 text-xs text-slate-400">{{ fmtDate(t.created_at, true) }} · {{ t.processed.toLocaleString() }} processed<span v-if="t.failed"> · <span class="text-red-600">{{ t.failed }} failed</span></span></div>
                        <div v-if="t.status === 'running'" class="mt-2 h-1.5 overflow-hidden rounded bg-slate-100"><div class="h-full bg-brand-600 transition-all" :style="{ width: pct(t) + '%' }" /></div>
                        <pre v-if="t.error" class="mt-2 max-h-32 overflow-auto rounded bg-red-50 p-2 text-xs text-red-800">{{ t.error }}</pre>
                    </div>
                    <p v-if="!transfers.length" class="px-5 py-8 text-center text-sm text-slate-400">Nothing yet.</p>
                </div>
            </section>
        </div>
    </AdminPage>
</template>
