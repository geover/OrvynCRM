<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Download, RefreshCw, Search } from 'lucide-vue-next';
import LineChart from '@/Components/charts/LineChart.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import StatTile from '@/Components/StatTile.vue';
import { num } from '@/lib/format';
import { fmtRelative, toast } from '@/lib/ui';

// How much of its plan each company uses, month by month: the platform in numbers and over the last six months, then every company with its
// users, texts and texting numbers against the tier's limits, what changed since last month, the alerts it was sent, and its own history.
const props = defineProps({ period: String, periods: Array, companies: Array, trend: { type: Array, default: () => [] }, measured_at: String });
const page = usePage();

const FILTERS = [['all', 'All'], ['at', 'At a limit'], ['near', 'Near a limit (80%+)'], ['ok', 'Fine']];
const SORTS = [['level', 'Closest to a limit'], ['name', 'Company name'], ['sms', 'Most texts'], ['users', 'Most users'], ['emails', 'Most e-mails']];
const filter = ref('all');
const sort = ref('level');
const q = ref('');
const open = ref(new Set());                       // companies whose detail is showing

const counts = computed(() => ({ all: props.companies.length, at: props.companies.filter((c) => c.level === 'at').length, near: props.companies.filter((c) => c.level === 'near').length, ok: props.companies.filter((c) => c.level === 'ok').length }));
const pct = (u) => (u.limit ? Math.round((100 * u.used) / u.limit) : 0);
const worst = (c) => Math.max(pct(c.users), pct(c.sms), pct(c.numbers));
const rows = computed(() => {
    const term = q.value.trim().toLowerCase();
    const list = props.companies.filter((c) => (filter.value === 'all' || c.level === filter.value) && (!term || `${c.name} ${c.slug ?? ''}`.toLowerCase().includes(term)));
    const by = { level: (a, b) => worst(b) - worst(a), name: (a, b) => a.name.localeCompare(b.name), sms: (a, b) => b.sms.used - a.sms.used, users: (a, b) => b.users.used - a.users.used, emails: (a, b) => b.work.emails - a.work.emails };
    return [...list].sort(by[sort.value]);
});

// the platform as a whole, this month and last
const mine = computed(() => props.trend[props.trend.length - 1] ?? { companies: 0, users: 0, sms: 0, numbers: 0, at: 0, near: 0 });
const before = computed(() => props.trend[props.trend.length - 2] ?? null);
const emailsTotal = computed(() => props.companies.reduce((n, c) => n + c.work.emails, 0));
const delta = (now, was) => (was === null || was === undefined ? null : { diff: now - was, text: was === 0 ? (now === 0 ? 'same as last month' : 'new this month') : `${now >= was ? '+' : ''}${Math.round((100 * (now - was)) / was)}% vs last month`, up: now >= was });
const tileDelta = (key) => delta(mine.value[key], before.value?.[key]);

const short = (p) => new Date(`${p}-01T00:00:00`).toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
const monthLabel = (p) => new Date(`${p}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
const labels = computed(() => props.trend.map((t) => short(t.period)));

const toggle = (id) => { const s = new Set(open.value); s.has(id) ? s.delete(id) : s.add(id); open.value = s; };
const pick = (e) => router.get('/platform/usage', { period: e.target.value }, { preserveScroll: true, preserveState: true });
const refresh = () => router.post('/platform/usage/refresh', {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Done') });

// what is on screen as a CSV
const csvCell = (v) => { const t = String(v ?? ''); return /[",\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
function exportCsv() {
    const head = ['Company', 'Status', 'Tier', 'Users', 'Users limit', 'Texts', 'Texts limit', 'Numbers', 'Numbers limit', 'E-mails sent', 'Automation runs', 'Level'];
    const lines = [head, ...rows.value.map((c) => [c.name, c.status, c.tier, c.users.used, c.users.limit ?? 'unlimited', c.sms.used, c.sms.limit, c.numbers.used, c.numbers.limit, c.work.emails, c.work.runs, c.level])];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([`﻿${lines.map((l) => l.map(csvCell).join(',')).join('\n')}`], { type: 'text/csv;charset=utf-8' }));
    a.download = `usage-${props.period}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}
const LEVEL = { at: ['At a limit', '!bg-red-50 !text-red-700'], near: ['Near a limit', '!bg-amber-50 !text-amber-700'], ok: ['Fine', '!bg-emerald-50 !text-emerald-700'] };
const usageDelta = (c, key) => (c.before ? delta(c[key].used, c.before[key]) : null);
const INDIGO = '#6366f1', GREEN = '#10b981', AMBER = '#f59e0b';
</script>

<template>
    <Head title="Usage" />
    <PageHeader title="Usage" subtitle="How much of its plan each company uses: users, texts and texting numbers against the limits of its tier. Companies are told at 80% and 100%.">
        <label class="sr-only" for="us-period">Month</label>
        <select id="us-period" class="input !w-44" :value="period" data-testid="usage-period" @change="pick"><option v-for="p in periods" :key="p" :value="p">{{ monthLabel(p) }}</option></select>
        <button class="btn-outline" data-testid="usage-export" :disabled="!rows.length" @click="exportCsv"><Download class="size-4" /> Export CSV</button>
        <button class="btn-outline" data-testid="usage-refresh" @click="refresh"><RefreshCw class="size-4" /> Measure now</button>
    </PageHeader>
    <p class="-mt-3 mb-5 text-xs text-slate-400">{{ measured_at ? `Last measured ${fmtRelative(measured_at)}` : 'Not measured yet: use "Measure now"' }} · {{ monthLabel(period) }}</p>

    <section class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4 xl:grid-cols-7" aria-label="The platform this month" data-testid="usage-summary">
        <StatTile label="Companies" :value="num(mine.companies)" :hint="`measured in ${short(period)}`" />
        <StatTile label="At a limit" :value="num(counts.at)" :danger="counts.at > 0" hint="100% of something used" />
        <StatTile label="Near a limit" :value="num(counts.near)" hint="80% or more" />
        <StatTile label="Texts sent" :value="num(mine.sms)" :hint="tileDelta('sms')?.text ?? 'all companies'" />
        <StatTile label="Active users" :value="num(mine.users)" :hint="tileDelta('users')?.text ?? 'most active seen'" />
        <StatTile label="Texting numbers" :value="num(mine.numbers)" :hint="tileDelta('numbers')?.text ?? 'in use'" />
        <StatTile label="E-mails sent" :value="num(emailsTotal)" hint="all companies" />
    </section>

    <section v-if="trend.length > 1" class="mb-6 grid gap-4 lg:grid-cols-2" aria-label="The last six months">
        <div class="card p-5"><h2 class="mb-1 text-sm font-semibold">Texts sent, last six months</h2><p class="mb-3 text-xs text-slate-400">All companies together.</p>
            <LineChart title="Texts sent per month" :labels="labels" :height="170" :series="[{ name: 'Texts', color: INDIGO, data: trend.map((t) => t.sms), kind: 'bar' }]" /></div>
        <div class="card p-5"><h2 class="mb-1 text-sm font-semibold">Users, numbers and companies at a limit</h2><p class="mb-3 text-xs text-slate-400">Active users and texting numbers in use, and how many companies were at a limit.</p>
            <LineChart title="Users, numbers and companies at a limit per month" :labels="labels" :height="170" :series="[{ name: 'Active users', color: GREEN, data: trend.map((t) => t.users) }, { name: 'Texting numbers', color: AMBER, data: trend.map((t) => t.numbers) }, { name: 'Companies at a limit', color: '#ef4444', data: trend.map((t) => t.at) }]" /></div>
    </section>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Show companies">
            <button v-for="[v, label] in FILTERS" :key="v" type="button" role="tab" :aria-selected="filter === v" :data-filter="v" class="rounded-full border px-3 py-1 text-sm transition"
                :class="filter === v ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="filter = v">
                {{ label }} <span class="ml-1 tabular-nums" :class="v === 'at' && counts.at ? 'font-semibold text-red-600' : v === 'near' && counts.near ? 'font-semibold text-amber-700' : 'text-slate-400'">{{ counts[v] }}</span>
            </button>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-56 !pl-9" placeholder="Find a company…" aria-label="Find a company" data-testid="usage-search" /></div>
            <select v-model="sort" class="input !w-auto min-w-44" aria-label="Sort by" data-testid="usage-sort"><option v-for="[v, l] in SORTS" :key="v" :value="v">{{ l }}</option></select>
        </div>
    </div>

    <div class="card table-scroll" data-testid="usage-table">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr>
                <th class="w-8 px-2 py-3"><span class="sr-only">Details</span></th><th class="px-3 py-3">Company</th><th class="px-3 py-3">Tier</th><th class="px-3 py-3">Active users</th><th class="px-3 py-3">Texts this month</th><th class="px-3 py-3">Texting numbers</th>
                <th class="px-3 py-3 text-right">E-mails sent</th><th class="px-3 py-3 text-right">Automation runs</th><th class="px-3 py-3 text-right">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <template v-for="c in rows" :key="c.id">
                    <tr :data-company="c.slug" :class="c.level === 'at' ? 'bg-red-50/40' : c.level === 'near' ? 'bg-amber-50/40' : ''">
                        <td class="px-2 py-3"><button type="button" class="btn-ghost !px-1" :aria-expanded="open.has(c.id)" :aria-label="`Details of ${c.name}`" data-testid="usage-expand" @click="toggle(c.id)"><component :is="open.has(c.id) ? ChevronDown : ChevronRight" class="size-4" /></button></td>
                        <td class="px-3 py-3"><Link :href="`/platform/companies/${c.id}`" class="font-medium hover:underline">{{ c.name }}</Link><div v-if="c.status !== 'active'" class="text-xs text-slate-400">{{ c.status }}</div></td>
                        <td class="whitespace-nowrap px-3 py-3">Tier {{ c.tier }}</td>
                        <td class="px-3 py-3" data-cell="users"><SeatMeter :used="c.users.used" :max="c.users.limit" unit="users" compact /><p v-if="usageDelta(c, 'users')?.diff" class="mt-0.5 text-[11px]" :class="usageDelta(c, 'users').up ? 'text-slate-500' : 'text-slate-400'">{{ usageDelta(c, 'users').diff > 0 ? '+' : '' }}{{ usageDelta(c, 'users').diff }} since last month</p></td>
                        <td class="px-3 py-3" data-cell="sms"><SeatMeter :used="c.sms.used" :max="c.sms.limit" unit="texts" compact /><p v-if="usageDelta(c, 'sms')?.diff" class="mt-0.5 text-[11px] text-slate-400">{{ usageDelta(c, 'sms').text }}</p></td>
                        <td class="px-3 py-3" data-cell="numbers"><SeatMeter :used="c.numbers.used" :max="c.numbers.limit" unit="numbers" compact /></td>
                        <td class="px-3 py-3 text-right tabular-nums" data-cell="emails">{{ num(c.work.emails) }}</td>
                        <td class="px-3 py-3 text-right tabular-nums" data-cell="runs">{{ num(c.work.runs) }}</td>
                        <td class="px-3 py-3 text-right"><span class="badge" :class="LEVEL[c.level][1]" :data-testid="`level-${c.level}`">{{ LEVEL[c.level][0] }}</span></td>
                    </tr>
                    <tr v-if="open.has(c.id)" :data-detail="c.slug" class="bg-slate-50/60">
                        <td colspan="9" class="px-6 py-4">
                            <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                                <div>
                                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Last six months</h3>
                                    <table class="w-full text-left text-xs">
                                        <thead class="text-slate-400"><tr><th class="py-1 pr-4 font-medium">Month</th><th class="py-1 pr-4 font-medium">Users</th><th class="py-1 pr-4 font-medium">Texts</th><th class="py-1 pr-4 font-medium">Numbers</th><th class="py-1 font-medium">Status</th></tr></thead>
                                        <tbody class="divide-y divide-slate-200/70">
                                            <tr v-for="h in c.history" :key="h.period" :class="h.period === period && 'font-semibold'">
                                                <td class="py-1.5 pr-4">{{ monthLabel(h.period) }}</td>
                                                <template v-if="h.level"><td class="py-1.5 pr-4 tabular-nums">{{ num(h.users) }}</td><td class="py-1.5 pr-4 tabular-nums">{{ num(h.sms) }}</td><td class="py-1.5 pr-4 tabular-nums">{{ num(h.numbers) }}</td><td class="py-1.5"><span class="badge" :class="LEVEL[h.level][1]">{{ LEVEL[h.level][0] }}</span></td></template>
                                                <td v-else colspan="4" class="py-1.5 text-slate-400">not measured</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="space-y-3 text-xs">
                                    <div><h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">This month</h3>
                                        <dl class="space-y-1 text-slate-600">
                                            <div class="flex justify-between gap-3"><dt>New contacts</dt><dd class="tabular-nums">{{ num(c.work.contacts) }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt>E-mails sent</dt><dd class="tabular-nums">{{ num(c.work.emails) }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt>Automation runs</dt><dd class="tabular-nums">{{ num(c.work.runs) }}</dd></div>
                                        </dl></div>
                                    <div><h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Alerts sent to the company</h3>
                                        <p v-if="!c.alerts.users && !c.alerts.sms && !c.alerts.numbers" class="text-slate-400">None this month.</p>
                                        <ul v-else class="space-y-1 text-slate-600"><li v-for="[key, label] in [['users', 'Users'], ['sms', 'Texts'], ['numbers', 'Texting numbers']]" :key="key" v-show="c.alerts[key]">{{ label }}: told at {{ c.alerts[key] }}%</li></ul></div>
                                    <Link :href="`/platform/companies/${c.id}`" class="inline-block text-brand-700 hover:underline">Open {{ c.name }} →</Link>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr v-if="!rows.length"><td colspan="9" class="px-5 py-10 text-center text-slate-400">{{ companies.length ? 'No company matches.' : 'No usage recorded for this month yet.' }}</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-400">Measured every hour, and at once when a company's texts run out. Users show the most active users seen in the month; texts count from the 1st in the company's own time zone. E-mails and automation runs come from the platform's hourly counts. A company that is above a limit (for example after moving to a smaller tier) is shown as at a limit.</p>
</template>
