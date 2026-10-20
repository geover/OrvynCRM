<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Boxes, Columns3, Link2, Plus, Search, TrendingUp } from 'lucide-vue-next';
import LimitRequest from '@/Components/LimitRequest.vue';
import RequestOutcome from '@/Components/RequestOutcome.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { api } from '@/lib/api';
import { fmtDate, fmtRelative } from '@/lib/ui';

const page = usePage();
const objects = ref(null);
const limits = ref(null);                       // { data_objects: { used, max, left, full }, requests, pricing, ... } (administrators only)
const asking = ref(false);
const q = ref('');
const sort = ref('name');                       // name | records | recent
const isAdmin = computed(() => page.props.auth.user.is_admin);
const pending = computed(() => limits.value?.requests.find((r) => r.kind === 'data_objects' && r.status === 'pending'));
const last = computed(() => limits.value?.requests.find((r) => r.kind === 'data_objects' && r.status !== 'pending'));
const cleared = (id) => { limits.value.requests = limits.value.requests.filter((r) => r.id !== id); };
onMounted(async () => {
    objects.value = (await api.get('/data-objects', { stats: 1 })).data;
    if (isAdmin.value) limits.value = (await api.get('/limits')).data;
});

const totalRecords = computed(() => (objects.value ?? []).reduce((n, o) => n + o.records_count, 0));
const mostRecords = computed(() => Math.max(1, ...(objects.value ?? []).map((o) => o.records_count)));
const addedThisWeek = computed(() => (objects.value ?? []).reduce((n, o) => n + (o.stats?.added_7d ?? 0), 0));
const listed = computed(() => {
    const needle = q.value.trim().toLowerCase();
    const l = (objects.value ?? []).filter((o) => !needle || `${o.name} ${o.description ?? ''} ${o.fields.map((f) => f.label).join(' ')}`.toLowerCase().includes(needle));
    return l.sort((a, b) => sort.value === 'records' ? b.records_count - a.records_count : sort.value === 'recent' ? String(b.stats?.last_change ?? '').localeCompare(String(a.stats?.last_change ?? '')) : a.name.localeCompare(b.name));
});
const shown = (o) => (o.fields.filter((f) => f.show_in_list).length ? o.fields.filter((f) => f.show_in_list) : o.fields).slice(0, 5);
</script>

<template>
    <Head title="Data Objects" />
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="page-title">Data Objects</h1>
            <p v-if="objects" class="mt-1 text-sm text-slate-500">{{ objects.length }} object{{ objects.length === 1 ? '' : 's' }} · {{ totalRecords.toLocaleString() }} record{{ totalRecords === 1 ? '' : 's' }}<template v-if="addedThisWeek"> · <span class="text-emerald-700">{{ addedThisWeek.toLocaleString() }} added this week</span></template></p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <SeatMeter v-if="limits" :used="limits.data_objects.used" :max="limits.data_objects.max" unit="data objects" compact data-testid="object-meter" />
            <button v-if="limits?.data_objects.full && !pending" class="btn-primary" data-testid="request-objects" @click="asking = true"><Plus class="size-4" /> Request more data objects</button>
            <Link v-else-if="!limits?.data_objects.full" href="/objects/new" class="btn-primary"><Plus class="size-4" /> New object</Link>
        </div>
    </div>
    <p v-if="pending" class="-mt-3 mb-4 text-sm text-slate-600" data-testid="object-request-pending"><span class="font-medium">Request sent:</span> you asked for {{ pending.requested_limit }} data objects. The platform will get back to you.</p>
    <p v-else-if="limits?.data_objects.full" class="-mt-3 mb-4 text-sm text-amber-700">You have reached your limit of {{ limits.data_objects.max }} data objects. Request more to create another.</p>
    <RequestOutcome v-if="!pending && last" :request="last" what="data objects" class="-mt-3 mb-4" @dismissed="cleared" />

    <div v-if="objects?.length > 1" class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input v-model="q" class="input !w-72 !pl-9" placeholder="Find a data object or field…" aria-label="Find a data object" /></div>
        <select v-model="sort" class="input !w-48" aria-label="Order"><option value="name">By name</option><option value="records">Most records first</option><option value="recent">Changed most recently</option></select>
    </div>

    <div v-if="!objects" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">
        <div v-for="n in 3" :key="n" class="card space-y-4 p-5"><div class="h-10 animate-pulse rounded-lg bg-slate-100" /><div class="h-8 w-1/3 animate-pulse rounded bg-slate-100" /><div class="h-12 animate-pulse rounded-lg bg-slate-100" /></div>
    </div>
    <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Link v-for="o in listed" :key="o.id" :href="`/objects/${o.id}`" class="card group flex flex-col gap-4 p-5 transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-md">
            <div class="flex items-start gap-3">
                <div class="grid size-11 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600"><Boxes class="size-5" /></div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold">{{ o.name }}</div>
                    <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ o.description || `${o.fields.length} fields` }}</p>
                </div>
            </div>

            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <span><span class="text-2xl font-semibold tabular-nums">{{ o.records_count.toLocaleString() }}</span><span class="ml-1 text-sm font-normal text-slate-500">records</span></span>
                    <span v-if="o.stats?.added_7d" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700" title="Added in the last 7 days"><TrendingUp class="size-3" /> +{{ o.stats.added_7d.toLocaleString() }} this week</span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100" :title="`${Math.round((o.records_count / mostRecords) * 100)}% of the biggest object`"><div class="h-full rounded-full bg-brand-500" :style="{ width: `${Math.max(o.records_count ? 3 : 0, (o.records_count / mostRecords) * 100)}%` }" /></div>
            </div>

            <div class="flex flex-wrap gap-1.5">
                <span v-for="f in shown(o)" :key="f.key" class="badge">{{ f.label }}</span>
                <span v-if="o.fields.length > shown(o).length" class="badge !bg-transparent text-slate-400">+{{ o.fields.length - shown(o).length }} more</span>
                <span v-if="!o.fields.length" class="text-xs text-slate-400">No fields yet</span>
            </div>

            <div class="mt-auto flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-400">
                <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>{{ o.fields.length }} fields</span>
                    <span v-if="o.relationships?.length" class="flex items-center gap-1" :title="o.relationships.map((r) => r.label).join(', ')"><Link2 class="size-3" /> {{ o.relationships.length }} relationship{{ o.relationships.length === 1 ? '' : 's' }}</span>
                    <span v-if="o.stats?.boards" class="flex items-center gap-1"><Columns3 class="size-3" /> {{ o.stats.boards }} board{{ o.stats.boards === 1 ? '' : 's' }}</span>
                </span>
                <span :title="o.stats?.last_change ? fmtDate(o.stats.last_change, true) : ''">{{ o.stats?.last_change ? `Changed ${fmtRelative(o.stats.last_change)}` : 'No records yet' }}</span>
            </div>
        </Link>
        <p v-if="objects.length && !listed.length" class="col-span-full card px-5 py-10 text-center text-sm text-slate-400">No data object matches.</p>
        <p v-if="!objects.length" class="col-span-full text-sm text-slate-400">No data objects yet. Click “New object” to create one.</p>
    </div>

    <LimitRequest v-if="asking && limits" :limits="limits" kind="data_objects" @close="asking = false" @sent="(l) => { limits = l; asking = false; }" />
</template>
