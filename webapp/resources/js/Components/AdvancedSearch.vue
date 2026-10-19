<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import MergeFieldMenu from '@/Components/MergeFieldMenu.vue';
import Modal from '@/Components/Modal.vue';
import QueryEditor from '@/Components/QueryEditor.vue';
import { api } from '@/lib/api';
import { Download, Mail, MessageSquare } from 'lucide-vue-next';
import EmailComposer from '@/Pages/Contacts/partials/EmailComposer.vue';
import { insertAtCursor } from '@/lib/mergeFields';
import { confirmDialog, run } from '@/lib/ui';

// Old "Advanced Search": run a saved query, or build an ad-hoc one (optionally saving it).
const emit = defineEmits(['close']);
const mode = ref('saved');
const queries = ref([]);
const picked = ref('');
const result = ref(null);
const running = ref(false);
const q = ref({ scope: 'contact', data_object_id: null, definition: {} });
const saveName = ref('');
const editor = ref(null);
const last = ref(null);                 // what the results on screen came from: a saved query, or a search described in place
const action = ref(null);               // 'email' | 'sms': the message being written to everybody found
const smsBody = ref('');
const busy = ref(false);
const smsEl = ref(null);
async function addSmsField(token) {
    const r = insertAtCursor(smsEl.value, smsBody.value, token);
    smsBody.value = r.value;
    await nextTick();
    smsEl.value?.focus();
    smsEl.value?.setSelectionRange(r.caret, r.caret);
}

onMounted(async () => { queries.value = (await api.get('/queries')).data; picked.value = queries.value[0]?.id ?? ''; if (!queries.value.length) mode.value = 'build'; });

async function runSaved() {
    if (!picked.value) return;
    running.value = true;
    try { result.value = await api.get(`/queries/${picked.value}/run`, { limit: 100 }); last.value = { saved_query_id: picked.value }; } finally { running.value = false; }
}
async function runBuilt() {
    running.value = true;
    try {
        const def = { ...q.value.definition, ...(q.value.scope === 'data_object' ? { data_object_id: Number(q.value.data_object_id) } : {}) };
        result.value = await api.post('/queries/preview', { scope: q.value.scope, definition: def, limit: 100 });
        last.value = { scope: q.value.scope, definition: def };
    } catch (e) { run(async () => { throw e; }); } finally { running.value = false; }
}
async function saveBuilt() {
    if (!saveName.value.trim()) return;
    const ok = await run(() => api.post('/queries', { name: saveName.value, scope: q.value.scope, data_object_id: q.value.data_object_id, definition: q.value.definition }), 'Query saved');
    if (ok) { saveName.value = ''; queries.value = (await api.get('/queries')).data; }
}
// ---- what to do with everybody found (the same actions as a dashboard widget)
const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
async function download() {
    busy.value = true;
    try {
        const res = await fetch('/api/v1/queries/export', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'text/csv', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(last.value) });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message ?? 'Could not download');
        const url = URL.createObjectURL(await res.blob());
        Object.assign(document.createElement('a'), { href: url, download: `search-${new Date().toISOString().slice(0, 10)}.csv` }).click();
        URL.revokeObjectURL(url);
    } catch (e) { run(async () => { throw e; }); } finally { busy.value = false; }
}
async function sendAll(channel, body) {
    const n = result.value.total.toLocaleString();
    const scope = last.value.scope ?? queries.value.find((x) => x.id === last.value.saved_query_id)?.scope;
    const who = scope === 'data_object' ? 'contacts linked to these items' : 'contacts';
    if (!(await confirmDialog({ title: `${channel === 'email' ? 'Email' : 'Text'} ${n} ${who}?`, message: 'Opted-out and suppressed contacts are skipped automatically.', confirmLabel: 'Queue messages' }))) return;
    if (await run(() => api.post(`/queries/batch/${channel}`, { ...last.value, ...body }), 'Messages are being queued. Follow the progress under Blast progress.')) { action.value = null; smsBody.value = ''; }
}
function open(r) {
    emit('close');
    router.visit(r.contact_id ? `/contacts/${r.contact_id}` : r.contacts?.[0] ? `/contacts/${r.contacts[0].id}` : `/objects/${r.data_object_id}`);
}
const cell = (v) => (Array.isArray(v) ? v.join(', ') : v);
</script>

<template>
    <Modal title="Advanced search" width="max-w-4xl" @close="emit('close')">
        <div class="mb-4 flex w-fit rounded-lg bg-slate-100 p-1 text-sm">
            <button :class="mode === 'saved' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="mode = 'saved'; result = null">Saved queries</button>
            <button :class="mode === 'build' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="mode = 'build'; result = null">Build a search</button>
        </div>

        <div v-if="mode === 'saved'" class="flex gap-2" data-no-guard>
            <select v-model="picked" class="input"><option v-for="x in queries" :key="x.id" :value="x.id">{{ x.name }}</option></select>
            <button class="btn-primary shrink-0" :disabled="!picked || running" @click="runSaved">{{ running ? 'Running…' : 'Run query' }}</button>
        </div>
        <div v-else class="space-y-4" data-no-guard>
            <QueryEditor v-model="q" :preview="false" />
            <div class="flex flex-wrap items-center gap-2">
                <button class="btn-primary" :disabled="running" @click="runBuilt">{{ running ? 'Running…' : 'Run query' }}</button>
                <input v-model="saveName" class="input !w-56" placeholder="Name to save this search…" /><button class="btn-outline" :disabled="!saveName.trim()" @click="saveBuilt">Save as new query</button>
            </div>
        </div>

        <div v-if="result" class="mt-5 overflow-hidden rounded-lg border border-slate-200">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2 text-sm font-medium"><span>{{ result.total.toLocaleString() }} result{{ result.total === 1 ? '' : 's' }}<span v-if="result.total > result.rows.length" class="font-normal text-slate-400"> · showing the first {{ result.rows.length }}</span></span>
                <span class="flex flex-wrap gap-1.5" data-testid="search-actions">
                    <button type="button" class="btn-outline !py-1 text-xs" :disabled="busy || !result.total" title="Download everybody found as a CSV file" data-testid="search-csv" @click="download"><Download class="size-3.5" /> Download CSV</button>
                    <button type="button" class="btn-outline !py-1 text-xs" :disabled="!result.total" title="Send an e-mail to everybody found" data-testid="search-email" @click="action = 'email'"><Mail class="size-3.5" /> Email all</button>
                    <button type="button" class="btn-outline !py-1 text-xs" :disabled="!result.total" title="Send a text to everybody found" data-testid="search-sms" @click="action = 'sms'"><MessageSquare class="size-3.5" /> Text all</button>
                </span></div>
            <div class="max-h-80 overflow-auto"><table class="w-full text-sm">
                <thead class="sticky top-0 bg-white text-left text-xs uppercase tracking-wide text-slate-500"><tr><th v-for="c in result.columns" :key="c.key" class="px-4 py-2">{{ c.label }}</th><th /></tr></thead>
                <tbody class="divide-y divide-slate-100"><tr v-for="r in result.rows" :key="r.id" class="hover:bg-slate-50"><td v-for="c in result.columns" :key="c.key" class="px-4 py-2">{{ cell(r.values[c.key]) }}</td><td class="px-3 text-right"><button class="text-xs text-brand-700 hover:underline" @click="open(r)">Open</button></td></tr>
                    <tr v-if="!result.rows.length"><td :colspan="result.columns.length + 1" class="px-4 py-8 text-center text-slate-400">No matches.</td></tr></tbody></table></div>
        </div>

        <!-- the same forms as for a dashboard widget: e-mail is the one-contact form (templates, merge fields, attachments) -->
        <EmailComposer v-if="action === 'email'" :bulk="{ name: 'this search', count: result?.total }" @close="action = null" @submit="(body) => sendAll('email', body)" />
        <Modal v-if="action === 'sms'" title="Text everybody found" @close="action = null">
            <form id="sms-all" class="space-y-3" @submit.prevent="sendAll('sms', { body: smsBody })">
                <div><div class="mb-1 flex items-center justify-between"><label class="label !mb-0" for="sms-all-body">Message</label><MergeFieldMenu @pick="addSmsField" /></div>
                    <textarea id="sms-all-body" ref="smsEl" v-model="smsBody" rows="5" class="input" required maxlength="1600" data-testid="search-sms-body" />
                    <p class="mt-1 text-xs text-slate-400">Merge fields are filled in per contact when it is sent.</p></div>
            </form>
            <template #footer><button class="btn-ghost" @click="action = null">Cancel</button><button class="btn-primary" form="sms-all" :disabled="!smsBody.trim()">Send…</button></template>
        </Modal>
    </Modal>
</template>
