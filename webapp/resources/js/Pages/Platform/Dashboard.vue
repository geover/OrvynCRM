<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Inbox, MessageSquare, RefreshCw } from 'lucide-vue-next';
import CardBoard from '@/Components/CardBoard.vue';
import Donut from '@/Components/charts/Donut.vue';
import HBars from '@/Components/charts/HBars.vue';
import LineChart from '@/Components/charts/LineChart.vue';
import ImpactTable from '@/Components/ImpactTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import QueueDetails from '@/Components/QueueDetails.vue';
import StatTile from '@/Components/StatTile.vue';
import { mb, ms, num, pct, secs } from '@/lib/format';
import { fmtRelative } from '@/lib/ui';
import { saved } from '@/lib/visit';

const props = defineProps({
    range: String, labels: Array, series: Object, kpis: Object, companies: Array, top_automations: Array, queue: Object,
    collected_at: String, has_data: Boolean, thresholds: Object,
});

const RANGES = [['24h', 'Last 24 hours'], ['7d', '7 days'], ['30d', '30 days']];
const PALETTE = ['#6366f1', '#22d3ee', '#f59e0b', '#10b981', '#ec4899', '#8b5cf6', '#f97316', '#14b8a6'];
const INDIGO = '#6366f1', CYAN = '#22d3ee', AMBER = '#f59e0b', GREEN = '#10b981', PINK = '#ec4899', RED = '#ef4444';

const setRange = (r) => router.get('/platform/dashboard', { range: r }, { preserveScroll: true, preserveState: false });
const refresh = () => router.post('/platform/dashboard/refresh', {}, saved('Collecting…'));
const queueOpen = ref(false);

const rangeLabel = computed(() => RANGES.find(([r]) => r === props.range)?.[1].toLowerCase() ?? '');
const queueWaiting = computed(() => props.queue.queues.reduce((a, q) => a + q.pending, 0));
const queueRunning = computed(() => props.queue.queues.reduce((a, q) => a + q.running, 0));

// The nine key numbers.
const tiles = computed(() => {
    const k = props.kpis;
    const plural = (n, one, many) => (n === 1 ? one : many);

    return [
        { key: 'companies', label: 'Companies', value: k.companies_active, suffix: ` / ${k.companies}`, hint: 'active / total' },
        { key: 'users', label: 'Users', value: num(k.users), suffix: ` of ${num(k.seats)}`, hint: `${k.companies_full} ${plural(k.companies_full, 'company is', 'companies are')} at the limit` },
        { key: 'contacts', label: 'Contacts', value: num(k.contacts), hint: `+${num(k.new_contacts)} in ${rangeLabel.value}` },
        { key: 'activities', label: 'Activities', value: num(k.activities), hint: `${rangeLabel.value} · ${num(k.emails_sent)} emails sent` },
        { key: 'automations', label: 'Automations', value: num(k.automations_active), suffix: ` / ${num(k.automations_total)}`, hint: `active / total · ${num(k.automation_runs)} runs in ${rangeLabel.value}` },
        { key: 'jobs', label: 'Background jobs', value: num(k.jobs), hint: `${rangeLabel.value} · ${ms(k.avg_job_ms)} on average` },
        { key: 'worker-time', label: 'Worker time', value: secs(k.job_seconds), hint: `${pct(k.automation_share)} automations · ${pct(k.message_share)} messages` },
        { key: 'failures', label: 'Failures', value: num(k.jobs_failed), suffix: ' jobs', danger: !!(k.jobs_failed || k.automation_runs_failed), hint: `${num(k.automation_runs_failed)} automation runs failed · ${num(props.queue.failed)} in the failed list` },
        { key: 'queue', label: 'Queue right now', value: num(queueWaiting.value), suffix: ' waiting', hint: `${num(queueRunning.value)} running · databases ${mb(k.db_mb)}`, action: 'Click for details' },
    ];
});

// The charts' data.
const topActive = computed(() => props.companies.filter((c) => c.activities > 0).slice(0, 8).map((c, i) => ({ label: c.name, value: c.activities, color: PALETTE[i % PALETTE.length], hint: `${num(c.new_contacts)} new contacts · ${num(c.emails_sent)} emails sent` })));
const loadShare = computed(() => props.companies.filter((c) => c.job_seconds > 0).sort((a, b) => b.job_seconds - a.job_seconds).slice(0, 8).map((c, i) => ({ label: c.name, value: c.job_seconds, color: PALETTE[i % PALETTE.length] })));
const heaviestAutomations = computed(() => props.top_automations.map((a) => ({ label: a.name, value: a.total_ms / 1000, hint: `${a.company} · ${num(a.runs)} runs in 24 h · ${ms(a.avg_ms)} each${a.failed ? ` · ${a.failed} failed` : ''}`, color: a.failed ? RED : AMBER })));
const seatBars = computed(() => props.companies.filter((c) => c.max_users > 0).map((c) => ({ label: c.name, value: Math.round((c.users / c.max_users) * 100), hint: `${c.users} of ${c.max_users} users`, color: c.users >= c.max_users ? RED : c.users / c.max_users >= 0.8 ? AMBER : INDIGO })).sort((a, b) => b.value - a.value).slice(0, 8));
const queueBars = computed(() => props.queue.queues.map((q) => ({ label: q.name, value: q.pending + q.running, hint: `${q.pending} waiting · ${q.running} running`, color: q.pending > 1000 ? RED : CYAN })));

// The cards of the board, in their default order (the viewer can rearrange, hide and reset them: see CardBoard).
const cards = computed(() => [
    ...tiles.value.map((t) => ({ id: `kpi-${t.key}`, title: `${t.label} (number)`, span: 3, slot: 'kpi' })),
    { id: 'activity', title: 'Company activity', span: 8 },
    { id: 'top-active', title: 'Most active companies', span: 4 },
    { id: 'automations', title: 'Automations', span: 8 },
    { id: 'heavy', title: 'Heaviest automations', span: 4 },
    { id: 'jobs', title: 'Server and background workers', span: 8 },
    { id: 'share', title: 'Who uses the workers', span: 4 },
    { id: 'seats', title: 'User limits', span: 6 },
    { id: 'queue', title: 'Queue right now (chart)', span: 6 },
    { id: 'impact', title: 'Impact by company', span: 12 },
]);
</script>

<template>
    <Head title="Dashboard" />
    <PageHeader title="Dashboard" subtitle="What companies are doing, what their automations weigh, and what that costs the servers and background workers.">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg bg-slate-100 p-0.5 text-sm" role="group" aria-label="Period">
                <button v-for="[value, label] in RANGES" :key="value" type="button" :aria-pressed="range === value" :data-range="value" :class="range === value ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" class="rounded-md px-3 py-1.5" @click="setRange(value)">{{ label }}</button>
            </div>
            <button class="btn-outline" data-testid="refresh-metrics" @click="refresh"><RefreshCw class="size-4" /> Refresh data</button>
        </div>
    </PageHeader>

    <p class="-mt-3 mb-5 text-xs text-slate-400">{{ collected_at ? `Company totals collected ${fmtRelative(collected_at)}; ` : '' }}activity numbers update every hour, worker time as jobs finish.</p>

    <div v-if="!has_data" class="card mb-6 px-6 py-10 text-center text-slate-500" data-testid="dashboard-empty">
        <p class="font-medium text-slate-700">No data collected yet</p>
        <p class="mt-1 text-sm">The first numbers appear at the next hourly run. Use "Refresh data" to collect them now.</p>
    </div>

    <Link v-if="kpis.messages_unread" href="/platform/messages" class="mb-4 flex items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 hover:bg-sky-100" data-testid="dashboard-messages">
        <MessageSquare class="size-5 shrink-0" /><span><strong>{{ kpis.messages_unread }}</strong> unread {{ kpis.messages_unread === 1 ? 'message' : 'messages' }} from companies.</span>
    </Link>
    <Link v-if="kpis.seat_requests" href="/platform/requests" class="mb-4 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 hover:bg-amber-100" data-testid="dashboard-requests">
        <Inbox class="size-5 shrink-0" /><span><strong>{{ kpis.seat_requests }}</strong> {{ kpis.seat_requests === 1 ? 'request is' : 'requests are' }} waiting for more users, data objects or automations. Review {{ kpis.seat_requests === 1 ? 'it' : 'them' }}.</span>
    </Link>

    <CardBoard storage-key="platform-dashboard" :cards="cards">
        <template #kpi="{ card }">
            <template v-for="t in tiles" :key="t.key">
                <StatTile v-if="card.id === `kpi-${t.key}`" :label="t.label" :value="t.value" :suffix="t.suffix" :hint="t.hint" :danger="t.danger" :action="t.action" :data-kpi="t.key" :data-testid="t.action ? 'queue-open' : undefined" @click="queueOpen = true" />
            </template>
        </template>

        <template #activity>
            <section class="card p-5" aria-labelledby="h-activity">
                <h2 id="h-activity" class="mb-3 text-sm font-semibold">Company activity</h2>
                <LineChart title="Activities and new contacts over time" :labels="labels" :series="[{ name: 'Activities', color: INDIGO, data: series.activities }, { name: 'New contacts', color: GREEN, data: series.new_contacts }]" />
            </section>
        </template>

        <template #top-active>
            <section class="card p-5" aria-labelledby="h-top">
                <h2 id="h-top" class="mb-3 text-sm font-semibold">Most active companies</h2>
                <HBars :items="topActive" empty="No activity in this period" />
            </section>
        </template>

        <template #automations>
            <section class="card p-5" aria-labelledby="h-auto">
                <h2 id="h-auto" class="mb-1 text-sm font-semibold">Automations</h2>
                <p class="mb-3 text-xs text-slate-400">Runs started, runs that failed, and the worker time automations used.</p>
                <LineChart title="Automation runs and worker time" :labels="labels" :format="(v) => num(v)" :series="[{ name: 'Runs', color: AMBER, data: series.automation_runs, kind: 'bar' }, { name: 'Failed runs', color: RED, data: series.automation_runs_failed, kind: 'bar' }]" />
                <h3 class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Worker seconds used by automations</h3>
                <LineChart title="Worker seconds used by automations" :labels="labels" :height="150" :format="(v) => secs(v)" :series="[{ name: 'Automation worker time', color: PINK, data: series.automation_seconds }]" />
            </section>
        </template>

        <template #heavy>
            <section class="card p-5" aria-labelledby="h-heavy">
                <h2 id="h-heavy" class="mb-1 text-sm font-semibold">Heaviest automations</h2>
                <p class="mb-3 text-xs text-slate-400">By total run time in the last 24 hours.</p>
                <HBars :items="heaviestAutomations" :format="(v) => secs(v)" empty="No automation runs in the last 24 hours" />
            </section>
        </template>

        <template #jobs>
            <section class="card p-5" aria-labelledby="h-jobs">
                <h2 id="h-jobs" class="mb-1 text-sm font-semibold">Server and background workers</h2>
                <p class="mb-3 text-xs text-slate-400">Jobs processed and the worker seconds they took. Failures are shown in red.</p>
                <LineChart title="Background jobs processed" :labels="labels" :series="[{ name: 'Jobs', color: INDIGO, data: series.jobs, kind: 'bar' }, { name: 'Failed', color: RED, data: series.jobs_failed, kind: 'bar' }]" />
                <h3 class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Worker seconds</h3>
                <LineChart title="Worker seconds" :labels="labels" :height="150" :format="(v) => secs(v)" :series="[{ name: 'Worker time', color: CYAN, data: series.job_seconds }]" />
            </section>
        </template>

        <template #share>
            <section class="card p-5" aria-labelledby="h-share">
                <h2 id="h-share" class="mb-3 text-sm font-semibold">Who uses the workers</h2>
                <Donut :items="loadShare.map((i) => ({ label: i.label, value: i.value, color: i.color }))" empty="No background work in this period" center-label="Share of worker time by company" />
            </section>
        </template>

        <template #seats>
            <section class="card p-5" aria-labelledby="h-seats">
                <h2 id="h-seats" class="mb-1 text-sm font-semibold">User limits</h2>
                <p class="mb-3 text-xs text-slate-400">How full each company is.</p>
                <HBars :items="seatBars" :format="(v) => `${v}%`" empty="No companies yet" />
            </section>
        </template>

        <template #queue>
            <section class="card p-5" aria-labelledby="h-queue">
                <h2 id="h-queue" class="mb-1 flex items-baseline justify-between gap-2 text-sm font-semibold">Queue right now <button type="button" class="text-xs font-normal text-brand-700 hover:underline" data-testid="queue-open-chart" @click="queueOpen = true">Details</button></h2>
                <p class="mb-3 text-xs text-slate-400">{{ queue.driver === 'database' ? 'Jobs waiting and running per queue.' : `Per-queue depth is not available for the ${queue.driver || 'current'} driver.` }}</p>
                <HBars :items="queueBars" empty="Nothing waiting" />
            </section>
        </template>

        <template #impact><ImpactTable :companies="companies" :thresholds="thresholds" :range-label="rangeLabel" /></template>
    </CardBoard>

    <QueueDetails v-if="queueOpen" @close="queueOpen = false" />
</template>
