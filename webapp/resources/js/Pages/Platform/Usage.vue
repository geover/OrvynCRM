<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { toast } from '@/lib/ui';

// Every company against what its tier allows, month by month: active users, texts sent this month, texting numbers.
const props = defineProps({ period: String, periods: Array, companies: Array });
const page = usePage();

const FILTERS = [['all', 'All'], ['at', 'At a limit'], ['near', 'Near a limit (80%+)'], ['ok', 'Fine']];
const filter = ref('all');
const counts = computed(() => ({ all: props.companies.length, at: props.companies.filter((c) => c.level === 'at').length, near: props.companies.filter((c) => c.level === 'near').length, ok: props.companies.filter((c) => c.level === 'ok').length }));
const rows = computed(() => props.companies.filter((c) => filter.value === 'all' || c.level === filter.value));
const monthLabel = (p) => new Date(`${p}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
const pick = (e) => router.get('/platform/usage', { period: e.target.value }, { preserveScroll: true, preserveState: true });
const refresh = () => router.post('/platform/usage/refresh', {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Done') });
</script>

<template>
    <Head title="Usage" />
    <PageHeader title="Usage" subtitle="How much of its plan each company uses. Companies are told at 80% and 100%.">
        <label class="sr-only" for="us-period">Month</label>
        <select id="us-period" class="input !w-44" :value="period" data-testid="usage-period" @change="pick"><option v-for="p in periods" :key="p" :value="p">{{ monthLabel(p) }}</option></select>
        <button class="btn-outline" data-testid="usage-refresh" @click="refresh"><RefreshCw class="size-4" /> Measure now</button>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center gap-2" role="tablist" aria-label="Show companies">
        <button v-for="[v, label] in FILTERS" :key="v" type="button" role="tab" :aria-selected="filter === v" :data-filter="v" class="rounded-full border px-3 py-1 text-sm transition"
            :class="filter === v ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="filter = v">
            {{ label }} <span class="ml-1 tabular-nums" :class="v === 'at' && counts.at ? 'font-semibold text-red-600' : v === 'near' && counts.near ? 'font-semibold text-amber-700' : 'text-slate-400'">{{ counts[v] }}</span>
        </button>
    </div>

    <div class="card table-scroll" data-testid="usage-table">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Company</th><th class="px-3 py-3">Tier</th><th class="px-3 py-3">Active users</th><th class="px-3 py-3">Texts this month</th><th class="px-3 py-3">Texting numbers</th><th class="px-3 py-3"><span class="sr-only">Status</span></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in rows" :key="c.id" :data-company="c.slug" :class="c.level === 'at' ? 'bg-red-50/40' : c.level === 'near' ? 'bg-amber-50/40' : ''">
                    <td class="px-4 py-3"><Link :href="`/platform/companies/${c.id}`" class="font-medium hover:underline">{{ c.name }}</Link><div v-if="c.status !== 'active'" class="text-xs text-slate-400">{{ c.status }}</div></td>
                    <td class="px-3 py-3 whitespace-nowrap">Tier {{ c.tier }}</td>
                    <td class="px-3 py-3" data-cell="users"><SeatMeter :used="c.users.used" :max="c.users.limit" unit="users" compact /></td>
                    <td class="px-3 py-3" data-cell="sms"><SeatMeter :used="c.sms.used" :max="c.sms.limit" unit="texts" compact /></td>
                    <td class="px-3 py-3" data-cell="numbers"><SeatMeter :used="c.numbers.used" :max="c.numbers.limit" unit="numbers" compact /></td>
                    <td class="px-3 py-3 text-right"><span v-if="c.level === 'at'" class="badge !bg-red-50 !text-red-700" data-testid="level-at">At a limit</span><span v-else-if="c.level === 'near'" class="badge !bg-amber-50 !text-amber-700" data-testid="level-near">Near a limit</span></td>
                </tr>
                <tr v-if="!rows.length"><td colspan="6" class="px-5 py-10 text-center text-slate-400">{{ companies.length ? 'No company here.' : 'No usage recorded for this month yet.' }}</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-400">Measured every hour, and at once when a company's texts run out. Users show the most active users seen in the month; texts count from the 1st in the company's own time zone. A company that is above a limit (for example after moving to a smaller tier) is shown as at a limit.</p>
</template>
