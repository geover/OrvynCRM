<script setup>
import { computed, onMounted, ref } from 'vue';
import { CheckCheck, Plus, Send, Trash2, Webhook } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import ItemCollection from '@/Components/ItemCollection.vue';
import Modal from '@/Components/Modal.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const items = ref(null);
const modal = ref(false);
const d = ref({});
const secret = ref('');
const log = ref(null);
const error = ref('');

const activeCount = computed(() => (items.value ?? []).filter((w) => w.is_active).length);
const deliveries = computed(() => (items.value ?? []).reduce((n, w) => n + (w.deliveries_count ?? 0), 0));
const load = async () => (items.value = (await api.get('/webhooks')).data);
onMounted(load);

function open(w = null) { error.value = ''; secret.value = ''; d.value = w ? { ...w } : { name: '', url: 'https://', is_active: true }; modal.value = true; }
async function save() {
    error.value = '';
    try {
        const body = { name: d.value.name, url: d.value.url, is_active: !!d.value.is_active };
        const r = d.value.id ? await api.put(`/webhooks/${d.value.id}`, body) : await api.post('/webhooks', body);
        if (r.secret) { secret.value = r.secret; } else modal.value = false;
        load();
    } catch (e) { error.value = Object.values(e.errors ?? {})[0]?.[0] ?? e.message; }
}
async function remove(w) { if (await confirmDialog({ title: `Delete “${w.name}”?`, message: 'Automations that call it will fail.', danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/webhooks/${w.id}`), 'Deleted'); load(); } }
async function showLog(w) { log.value = { w, rows: (await api.get(`/webhooks/${w.id}/deliveries`)).data }; }
</script>

<template>
    <AdminPage title="Webhooks" subtitle="Outbound HTTPS endpoints your automations can call." :crumbs="[{ label: 'Automation', href: '/automations' }]">
        <template #actions><button class="btn-primary" @click="open()"><Plus class="size-4" /> Add endpoint</button></template>
                <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-3" data-testid="webhooks-summary" aria-label="Endpoints in numbers">
            <TicketTile :icon="Webhook" tone="bg-indigo-100 text-indigo-700" label="Endpoints" :value="items?.length ?? 0" hint="your automations can call" />
            <TicketTile :icon="CheckCheck" tone="bg-emerald-100 text-emerald-700" label="Active" :value="activeCount" hint="switched on" />
            <TicketTile class="col-span-2 lg:col-span-1" :icon="Send" tone="bg-sky-100 text-sky-700" label="Deliveries" :value="deliveries.toLocaleString()" hint="sent in all" />
        </section>
        <ItemCollection :items="items" storage-key="webhooks" :icon="Webhook" :search-text="(w) => `${w.name} ${w.url}`" search-label="Find an endpoint…" empty-title="No endpoints yet" empty-text="Add an HTTPS address and let an automation call it when something happens.">
            <template #title="{ item: w }">{{ w.name }}</template>
            <template #badges="{ item: w }"><span :class="w.is_active ? '!bg-emerald-50 !text-emerald-700' : ''" class="badge">{{ w.is_active ? 'active' : 'off' }}</span></template>
            <template #meta="{ item: w }"><span class="break-all font-mono">{{ w.url }}</span></template>
            <template #actions="{ item: w }"><button class="btn-outline" @click="showLog(w)">{{ w.deliveries_count }} deliveries</button><button class="btn-outline" @click="open(w)">Edit</button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete" @click="remove(w)"><Trash2 class="size-4" /></button></template>
            <template #empty><button class="btn-primary" @click="open()"><Plus class="size-4" /> Add endpoint</button></template>
        </ItemCollection>

        <Modal v-if="modal" :title="d.id ? 'Edit endpoint' : 'Add endpoint'" @close="modal = false">
            <div v-if="secret" class="space-y-3 text-sm"><p class="font-medium text-emerald-700">Endpoint created.</p><p>Verify each request with the <code class="rounded bg-slate-100 px-1">X-Orvyn-Signature</code> header (HMAC-SHA256 of the raw body). Copy this secret now — it won't be shown again:</p><code class="block break-all rounded bg-slate-900 p-3 text-xs text-slate-100">{{ secret }}</code></div>
            <form v-else id="wf" class="space-y-3" @submit.prevent="save">
                <div><label class="label">Name</label><input v-model="d.name" class="input" required /></div>
                <div><label class="label">URL</label><input v-model="d.url" type="url" class="input font-mono" required /><p class="mt-1 text-xs text-slate-400">HTTPS only. Private and internal network addresses are blocked.</p></div>
                <label class="flex items-center gap-2 text-sm"><input v-model="d.is_active" type="checkbox" class="rounded" /> Active</label>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            </form>
            <template #footer><template v-if="secret"><button class="btn-primary" @click="modal = false">Done</button></template><template v-else><button class="btn-ghost" @click="modal = false">Cancel</button><button class="btn-primary" form="wf">Save</button></template></template>
        </Modal>

        <Modal v-if="log" :title="`Deliveries — ${log.w.name}`" width="max-w-2xl" @close="log = null">
            <ul class="divide-y divide-slate-100 text-sm"><li v-for="x in log.rows" :key="x.id" class="flex items-center justify-between py-2"><span>{{ x.event }}</span><span class="text-xs text-slate-400">{{ x.response_status ?? '—' }} · {{ x.attempts }} attempt{{ x.attempts === 1 ? '' : 's' }} · {{ fmtDate(x.created_at, true) }}</span></li><li v-if="!log.rows.length" class="py-8 text-center text-slate-400">No deliveries yet.</li></ul>
        </Modal>
    </AdminPage>
</template>
