<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, RefreshCw, Users } from 'lucide-vue-next';
import CardBoard from '@/Components/CardBoard.vue';
import HBars from '@/Components/charts/HBars.vue';
import LineChart from '@/Components/charts/LineChart.vue';
import CompanyServicesCard from '@/Components/company/CompanyServicesCard.vue';
import ObjectLimitsCard from '@/Components/company/ObjectLimitsCard.vue';
import ObjectServicesTable from '@/Components/company/ObjectServicesTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import StatTile from '@/Components/StatTile.vue';
import { mb, num } from '@/lib/format';
import { fmtDate, fmtRelative } from '@/lib/ui';
import { saved } from '@/lib/visit';

const props = defineProps({
    company: Object, users: Number, collected_at: String, counts: Object, automations: Object, activities_total: Number, db_mb: Number,
    breakdown: Array, labels: Array, series: Object, week: Object, services: Object, limits: Object,
});

const INDIGO = '#6366f1', GREEN = '#10b981', AMBER = '#f59e0b', CYAN = '#22d3ee';

const collect = () => router.post(`/platform/companies/${props.company.id}/collect`, {}, saved('Collecting…'));
const open = () => router.post(`/platform/companies/${props.company.id}/open`);

// The four numbers on top, then everything else it holds.
const tiles = computed(() => [
    { key: 'contacts', label: 'Contacts', value: props.counts.contacts, hint: `+${num(props.week.new_contacts)} in 7 days` },
    { key: 'data_objects', label: 'Data objects', value: props.counts.data_objects ?? 0, hint: 'object types defined' },
    { key: 'records', label: 'Records', value: props.counts.records, hint: 'in all data objects' },
    { key: 'users', label: 'Users', value: props.users, hint: `of ${props.company.max_users} allowed` },
]);
const others = computed(() => [
    ['Tasks (open)', props.counts.tasks_open], ['Tasks (done)', props.counts.tasks_done], ['Notes', props.counts.notes], ['Tags', props.counts.tags],
    ['Forms', props.counts.forms], ['Form submissions', props.counts.submissions], ['Automations', props.automations.total, `${props.automations.active} active`],
    ['E-mails', props.counts.emails], ['Text messages', props.counts.sms], ['Saved queries', props.counts.saved_queries], ['Dashboard widgets', props.counts.widgets],
    ['Webhooks', props.counts.webhooks], ['Price lists', props.counts.price_lists], ['Attachments', props.counts.attachments], ['Activities (all time)', props.activities_total],
]);
const breakdownBars = computed(() => props.breakdown.map((b, i) => ({ label: b.name, value: b.records, color: [INDIGO, CYAN, AMBER, GREEN, '#ec4899', '#8b5cf6'][i % 6] })));

// The cards of the board, in their default order (the viewer can rearrange, hide and reset them: see CardBoard).
const cards = computed(() => [
    ...tiles.value.map((t) => ({ id: `kpi-${t.key}`, title: `${t.label} (number)`, span: 3, slot: 'kpi' })),
    { id: 'activity', title: 'Activity, last 30 days', span: 8 },
    { id: 'objects', title: 'Records per data object', span: 4 },
    { id: 'services', title: 'Background services', span: 12 },
    ...(props.services.objects.length ? [{ id: 'object-services', title: 'Background services per data object', span: 12 }] : []),
    ...(props.limits ? [{ id: 'limits', title: 'Data objects and automations', span: 12 }] : []),
    { id: 'other', title: 'Everything else it holds', span: 12 },
    { id: 'load', title: 'Background work, last 30 days', span: 12 },
]);
</script>

<template>
    <Head :title="`${company.name} overview`" />
    <PageHeader :title="company.name" :subtitle="`${company.slug} · ${company.status}${company.plan ? ` · ${company.plan}` : ''} · created ${fmtDate(company.created_at)}`">
        <Link href="/platform" class="btn-ghost"><ArrowLeft class="size-4" /> Companies</Link>
        <Link :href="`/platform/companies/${company.id}/users`" class="btn-outline"><Users class="size-4" /> People</Link>
        <button class="btn-outline" :disabled="company.status !== 'active'" @click="open"><ExternalLink class="size-4" /> Open</button>
        <button class="btn-outline" data-testid="collect-company" :disabled="company.status !== 'active'" @click="collect"><RefreshCw class="size-4" /> Refresh numbers</button>
    </PageHeader>
    <p class="-mt-3 mb-5 text-xs text-slate-400">{{ collected_at ? `Counted ${fmtRelative(collected_at)}` : 'Not counted yet: use "Refresh numbers"' }}. Counts update every hour. Database {{ mb(db_mb) }}.</p>

    <CardBoard storage-key="platform-company-overview" :cards="cards">
        <template #kpi="{ card }">
            <template v-for="t in tiles" :key="t.key">
                <StatTile v-if="card.id === `kpi-${t.key}`" :label="t.label" :value="num(t.value)" :hint="t.hint" :data-count="t.key">
                    <div v-if="t.key === 'users'" class="mt-2"><SeatMeter :used="users" :max="company.max_users" compact /></div>
                </StatTile>
            </template>
        </template>

        <template #activity>
            <section class="card p-5" aria-labelledby="co-activity">
                <h2 id="co-activity" class="mb-3 text-sm font-semibold">Activity, last 30 days</h2>
                <LineChart title="Activities and new contacts, last 30 days" :labels="labels" :series="[{ name: 'Activities', color: INDIGO, data: series.activities }, { name: 'New contacts', color: GREEN, data: series.new_contacts }]" />
            </section>
        </template>

        <template #objects>
            <section class="card p-5" aria-labelledby="co-objects">
                <h2 id="co-objects" class="mb-1 text-sm font-semibold">Records per data object</h2>
                <p class="mb-3 text-xs text-slate-400">{{ num(counts.records) }} records in {{ num(counts.data_objects ?? 0) }} {{ counts.data_objects === 1 ? 'object' : 'objects' }}.</p>
                <HBars :items="breakdownBars" empty="No data objects yet" />
            </section>
        </template>

        <template #services><CompanyServicesCard :company="company" :services="services" /></template>
        <template #object-services><ObjectServicesTable :company="company" :services="services" /></template>
        <template #limits><ObjectLimitsCard :company="company" :limits="limits" /></template>

        <template #other>
            <section class="card p-5" aria-labelledby="co-other">
                <h2 id="co-other" class="mb-3 text-sm font-semibold">Everything else it holds</h2>
                <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="[label, value, hint] in others" :key="label" class="flex items-baseline justify-between gap-3 border-b border-slate-100 pb-2" :data-other="label">
                        <dt class="text-sm text-slate-600">{{ label }}<span v-if="hint" class="ml-1 text-xs text-slate-400">({{ hint }})</span></dt>
                        <dd class="text-sm font-semibold tabular-nums">{{ num(value) }}</dd>
                    </div>
                </dl>
            </section>
        </template>

        <template #load>
            <section class="card p-5" aria-labelledby="co-load">
                <h2 id="co-load" class="mb-1 text-sm font-semibold">Background work, last 30 days</h2>
                <p class="mb-3 text-xs text-slate-400">Jobs processed and the worker seconds they took, for this company only.</p>
                <LineChart title="Background jobs, last 30 days" :labels="labels" :height="170" :series="[{ name: 'Jobs', color: INDIGO, data: series.jobs, kind: 'bar' }, { name: 'Automation runs', color: AMBER, data: series.automation_runs, kind: 'bar' }]" />
            </section>
        </template>
    </CardBoard>
</template>
