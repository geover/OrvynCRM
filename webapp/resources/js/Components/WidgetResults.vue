<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Download, Mail, MessageSquare, Search, X } from 'lucide-vue-next';
import DataTable from '@/Components/DataTable.vue';
import MergeFieldMenu from '@/Components/MergeFieldMenu.vue';
import Modal from '@/Components/Modal.vue';
import TablePagination from '@/Components/TablePagination.vue';
import EmailComposer from '@/Pages/Contacts/partials/EmailComposer.vue';
import { api } from '@/lib/api';
import { insertAtCursor } from '@/lib/mergeFields';
import { useStored } from '@/lib/stored';
import { confirmDialog, run } from '@/lib/ui';

// Every result of a dashboard widget as a table (shown in a wide pop-up from the dashboard): search, sort by any column, numbered pages, and the actions (CSV, e-mail, text) for the rows
// that are ticked, or for everything found when nothing is.
const props = defineProps({ widgetId: { type: Number, required: true }, name: { type: String, default: 'this widget' } });

const result = ref({ columns: [], rows: [], total: 0 });
const loading = ref(true);
const error = ref('');
const page = ref(1);
const perPage = useStored('perpage:widget', 25);
const sort = ref('');                  // '' = the order of the query itself, 'key' ascending, '-key' descending
const q = ref('');
const selected = ref([]);
const everything = ref(false);         // "all N results" was chosen, not only the rows on this page
const compose = ref(null);             // 'email' | 'sms'
const sms = ref({ body: '' });
const smsEl = ref(null);

const columns = computed(() => result.value.columns.map((c, i) => ({ key: c.key, label: c.label, sort: c.key, title: i === 0 })));
const rows = computed(() => result.value.rows.map((r) => ({ id: r.id, raw: r, values: r.values })));
const total = computed(() => result.value.total ?? 0);
const meta = computed(() => ({
    current_page: page.value, per_page: perPage.value, total: total.value, last_page: Math.max(1, Math.ceil(total.value / perPage.value)),
    from: total.value ? (page.value - 1) * perPage.value + 1 : 0, to: Math.min(total.value, page.value * perPage.value),
}));

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const desc = sort.value.startsWith('-');
        const res = await api.get(`/widgets/${props.widgetId}/data`, { limit: perPage.value, offset: (page.value - 1) * perPage.value, sort: sort.value.replace(/^-/, ''), dir: desc ? 'desc' : 'asc', search: q.value });
        if (res.total && (page.value - 1) * perPage.value >= res.total) { page.value = Math.max(1, Math.ceil(res.total / perPage.value)); return load(); }
        result.value = res;
        selected.value = [];
    } catch (e) { error.value = e.message; } finally { loading.value = false; }
}
const goTo = (n) => { page.value = n; load(); window.scrollTo({ top: 0 }); };
const resize = (n) => { perPage.value = n; goTo(1); };
const fromStart = () => { page.value = 1; load(); };
let t;
watch(q, () => { clearTimeout(t); t = setTimeout(fromStart, 300); });
watch(sort, fromStart);
watch(selected, () => { everything.value = false; });
onMounted(load);

const target = (r) => (r.contact_id ? `/contacts/${r.contact_id}` : r.contacts?.[0] ? `/contacts/${r.contacts[0].id}` : r.data_object_id ? `/objects/${r.data_object_id}` : null);
const cell = (v) => (Array.isArray(v) ? v.join(', ') : v);

// What an action applies to: the ticked rows ('selected'), or everything the search finds ('all').
const picked = (m) => (m === 'selected' && selected.value.length && !everything.value ? selected.value : null);
const scopeLabel = (m) => { const n = picked(m)?.length ?? total.value; return `${n.toLocaleString()} ${n === 1 ? 'result' : 'results'}`; };
const params = (m) => ({ search: q.value || undefined, ids: picked(m) ?? undefined });
const csvHref = (m) => {
    const p = new URLSearchParams();
    if (q.value) p.set('search', q.value);
    (picked(m) ?? []).forEach((id) => p.append('ids[]', id));
    const query = p.toString();
    return `/files/widgets/${props.widgetId}/export${query ? `?${query}` : ''}`;
};

const mode = ref('all');
const start = (type, m) => { mode.value = m; sms.value = { body: '' }; compose.value = type; };
async function addSmsField(token) {
    const r = insertAtCursor(smsEl.value, sms.value.body, token);
    sms.value.body = r.value;
    await nextTick();
    smsEl.value?.focus();
    smsEl.value?.setSelectionRange(r.caret, r.caret);
}
async function send(body) {
    const email = compose.value === 'email';
    if (!(await confirmDialog({ title: `${email ? 'Email' : 'Text'} ${scopeLabel(mode.value)}?`, message: 'Opted-out and suppressed contacts are skipped automatically.', confirmLabel: 'Queue messages' }))) return;
    if (await run(() => api.post(`/widgets/${props.widgetId}/batch/${compose.value}`, { ...body, ...params(mode.value) }), 'Messages are being queued. Follow the progress under Blast progress.')) compose.value = null;
}
</script>

<template>
    <div data-testid="widget-results">
    <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

    <DataTable v-model:selected="selected" v-model:sort="sort" :columns="columns" :rows="rows" :loading="loading" selectable contained noun="row" :storage-key="`widget-${widgetId}`" empty-text="Nothing matches right now.">
        <template #toolbar>
            <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-72 !pl-9" placeholder="Search these results…" aria-label="Search these results" data-testid="widget-search" /></div>
            <button v-if="q" type="button" class="btn-ghost" @click="q = ''"><X class="size-4" /> Clear</button>
            <span class="mx-1 hidden h-6 w-px bg-slate-200 sm:block" aria-hidden="true" />
            <a :href="csvHref('all')" class="btn-outline" data-testid="widget-csv"><Download class="size-4" /> Download CSV</a>
            <button type="button" class="btn-outline" data-testid="widget-email-all" @click="start('email', 'all')"><Mail class="size-4" /> Email all</button>
            <button type="button" class="btn-outline" data-testid="widget-sms-all" @click="start('sms', 'all')"><MessageSquare class="size-4" /> Text all</button>
        </template>

        <template #bulk>
            <button v-if="selected.length === rows.length && total > rows.length && !everything" type="button" class="text-brand-700 underline-offset-2 hover:underline" data-testid="widget-select-everything" @click="everything = true">Select all {{ total.toLocaleString() }} results</button>
            <span v-if="everything" class="text-brand-700" data-testid="widget-everything">All {{ total.toLocaleString() }} results are selected</span>
            <a :href="csvHref('selected')" class="btn-outline !py-1.5" data-testid="widget-csv-selected"><Download class="size-4" /> Download CSV</a>
            <button type="button" class="btn-outline !py-1.5" data-testid="widget-email-selected" @click="start('email', 'selected')"><Mail class="size-4" /> Email</button>
            <button type="button" class="btn-outline !py-1.5" data-testid="widget-sms-selected" @click="start('sms', 'selected')"><MessageSquare class="size-4" /> Text</button>
        </template>

        <template v-for="c in columns" :key="c.key" #[`cell-${c.key}`]="{ row }">
            <Link v-if="c.title && target(row.raw)" :href="target(row.raw)" class="font-medium text-brand-700 hover:underline">{{ cell(row.values[c.key]) }}</Link>
            <template v-else>{{ cell(row.values[c.key]) }}</template>
        </template>
        <template #actions="{ row }"><Link v-if="target(row.raw)" :href="target(row.raw)" class="text-xs text-brand-700 hover:underline">Open</Link></template>
        <template #footer><TablePagination :meta="meta" :loading="loading" @page="goTo" @size="resize" /></template>
    </DataTable>

    <EmailComposer v-if="compose === 'email'" :bulk="{ name: props.name, count: picked(mode)?.length ?? total }" @close="compose = null" @submit="send" />
    <Modal v-if="compose === 'sms'" :title="`Text ${scopeLabel(mode)}`" @close="compose = null">
        <form id="wsms" class="space-y-3" @submit.prevent="send({ body: sms.body })">
            <div><div class="mb-1 flex items-center justify-between"><label class="label !mb-0" for="widget-sms">Message</label><MergeFieldMenu @pick="addSmsField" /></div>
                <textarea id="widget-sms" ref="smsEl" v-model="sms.body" rows="5" class="input" required maxlength="1600" />
                <p class="mt-1 text-xs text-slate-400">Merge fields are filled in per contact when it is sent.</p></div>
        </form>
        <template #footer><button class="btn-ghost" @click="compose = null">Cancel</button><button class="btn-primary" form="wsms">Send…</button></template>
    </Modal>
    </div>
</template>
