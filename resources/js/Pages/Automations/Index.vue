<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { History, Pause, Play, Plus, Trash2, Zap } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import LimitRequest from '@/Components/LimitRequest.vue';
import RequestOutcome from '@/Components/RequestOutcome.vue';
import Modal from '@/Components/Modal.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const items = ref(null);
const runs = ref(null);        // {automation, rows}
const load = async () => (items.value = (await api.get('/automations')).data);
const limits = ref(null);                        // { objects: [{ id, name, used, max, full, custom }], requests, pricing, ... }
const loadLimits = async () => (limits.value = (await api.get('/limits')).data);
const asking = ref(null);                        // the data object a request is being written for
const requestFor = (o, status) => limits.value?.requests.find((r) => r.kind === 'automations' && r.data_object_id === o.id && (status ? r.status === status : r.status !== 'pending'));
const usage = computed(() => (limits.value?.objects ?? []).filter((o) => o.used > 0 || o.full || requestFor(o)));
const cleared = (id) => { limits.value.requests = limits.value.requests.filter((r) => r.id !== id); };
onMounted(() => { load(); loadLimits().catch(() => {}); });

const toggle = async (a) => { await run(() => api.patch(`/automations/${a.id}`, { status: a.status === 'active' ? 'paused' : 'active' }), a.status === 'active' ? 'Paused' : 'Resumed'); load(); };
const runNow = (a) => run(() => api.post(`/automations/${a.id}/run`), 'Evaluation queued');
async function remove(a) {
    if (await confirmDialog({ title: `Delete “${a.name}”?`, message: 'Its run history is deleted too. Actions already taken are not undone.', danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/automations/${a.id}`), 'Deleted'); load(); }
}
async function showRuns(a) { runs.value = { automation: a, rows: (await api.get(`/automations/${a.id}/runs`)).data }; }
const SCHEDULE = { on_change: 'When data changes', every_minute: 'Every minute', hourly: 'Hourly', daily: 'Daily' };
const color = { done: '!bg-emerald-50 !text-emerald-700', failed: '!bg-red-50 !text-red-700', pending: '!bg-amber-50 !text-amber-700' };
</script>

<template>
    <AdminPage title="Automation" subtitle="Configure automated processes by choosing conditions and actions.">
        <template #actions><Link href="/admin/webhooks" class="btn-outline">Webhooks</Link><Link href="/automations/new" class="btn-primary"><Plus class="size-4" /> New automation</Link></template>
        <section v-if="usage.length" class="card mb-4 p-4" aria-label="Automations per data object" data-testid="automation-limits">
            <h2 class="mb-2 text-sm font-semibold">Automations per data object</h2>
            <ul class="grid gap-x-8 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="o in usage" :key="o.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1" :data-object-meter="o.name">
                    <div class="min-w-0"><div class="truncate text-sm font-medium">{{ o.name }}</div><SeatMeter :used="o.used" :max="o.max" unit="automations" compact /></div>
                    <span v-if="requestFor(o, 'pending')" class="badge shrink-0 !bg-amber-50 !text-amber-700">Request sent</span>
                    <button v-else-if="o.full" class="btn-outline shrink-0 !py-1" data-testid="request-automations" @click="asking = o">Request more</button>
                    <RequestOutcome v-if="requestFor(o)" :request="requestFor(o)" :what="`automations for “${o.name}”`" class="w-full" @dismissed="cleared" />
                </li>
            </ul>
        </section>
        <div class="card divide-y divide-slate-100">
            <div v-for="a in items" :key="a.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm">
                <div class="flex min-w-0 gap-3"><div class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600"><Zap class="size-4" /></div>
                    <div class="min-w-0"><Link :href="`/automations/${a.id}`" class="font-medium hover:text-brand-700">{{ a.name }}</Link>
                        <div class="mt-1 flex flex-wrap gap-1.5 text-xs text-slate-500"><span class="badge">{{ a.data_object?.name ?? 'No object' }}</span><span class="badge">{{ SCHEDULE[a.schedule] }}</span><span class="badge">{{ a.actions.length }} action{{ a.actions.length === 1 ? '' : 's' }}</span><span class="badge">{{ a.runs_count }} runs</span><span v-if="a.last_run_at">last checked {{ fmtDate(a.last_run_at, true) }}</span></div></div></div>
                <div class="flex items-center gap-1"><span :class="a.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : '!bg-amber-50 !text-amber-700'" class="badge mr-1">{{ a.status }}</span>
                    <button class="btn-outline" @click="runNow(a)">Run now</button><button class="btn-ghost !px-1.5" title="History" @click="showRuns(a)"><History class="size-4" /></button>
                    <button class="btn-ghost !px-1.5" :title="a.status === 'active' ? 'Pause' : 'Resume'" @click="toggle(a)"><component :is="a.status === 'active' ? Pause : Play" class="size-4" /></button>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(a)"><Trash2 class="size-4" /></button></div>
            </div>
            <p v-if="items && !items.length" class="px-5 py-10 text-center text-sm text-slate-400">No automations yet.</p>
        </div>

        <LimitRequest v-if="asking && limits" :limits="limits" kind="automations" :object="asking" @close="asking = null" @sent="(l) => { limits = l; asking = null; }" />

        <Modal v-if="runs" :title="`History — ${runs.automation.name}`" width="max-w-2xl" @close="runs = null">
            <ul class="divide-y divide-slate-100 text-sm">
                <li v-for="r in runs.rows" :key="r.id" class="py-3"><div class="flex items-center justify-between"><span>Record #{{ r.data_object_record_id }}</span><span><span class="badge" :class="color[r.status]">{{ r.status }}</span> <span class="ml-2 text-xs text-slate-400">{{ fmtDate(r.created_at, true) }}</span></span></div>
                    <div class="mt-1 flex flex-wrap gap-1"><span v-for="(x, i) in r.result ?? []" :key="i" class="badge" :class="x.status === 'failed' ? '!bg-red-50 !text-red-700' : ''" :title="x.error">{{ (x.type || '').replace('_', ' ') }}: {{ x.status }}</span></div></li>
                <li v-if="!runs.rows.length" class="py-8 text-center text-slate-400">It hasn't run yet.</li>
            </ul>
        </Modal>
    </AdminPage>
</template>
