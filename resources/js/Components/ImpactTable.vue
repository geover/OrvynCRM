<script setup>
import SeatMeter from '@/Components/SeatMeter.vue';
import { mb, num, pct, secs } from '@/lib/format';

// "Impact by company" on the platform dashboard: what each company uses, and how heavily it leans on the shared workers.
defineProps({ companies: { type: Array, required: true }, thresholds: { type: Object, required: true }, rangeLabel: { type: String, required: true } });

const LEVEL = { heavy: ['Heavy', 'bg-red-100 text-red-700'], busy: ['Busy', 'bg-amber-100 text-amber-800'], normal: ['Normal', 'bg-emerald-100 text-emerald-700'] };
const BAR = { heavy: 'bg-red-500', busy: 'bg-amber-500', normal: 'bg-emerald-500' };
</script>

<template>
    <section class="card overflow-x-auto" aria-labelledby="h-impact">
        <div class="px-5 pt-5">
            <h2 id="h-impact" class="text-sm font-semibold">Impact by company</h2>
            <p class="mt-1 text-xs text-slate-400">{{ rangeLabel }}. A company using {{ pct(thresholds.heavy) }} or more of all worker time is flagged Heavy, {{ pct(thresholds.busy) }} or more Busy (only when there are several companies).</p>
        </div>
        <table class="mt-3 w-full text-sm" aria-label="Impact by company">
            <thead class="border-y border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-5 py-3">Company</th><th class="px-3 py-3">Users</th><th class="px-3 py-3 text-right">Activities</th><th class="px-3 py-3 text-right">Automations</th>
                    <th class="px-3 py-3 text-right">Jobs</th><th class="px-3 py-3 text-right">Worker time</th><th class="px-3 py-3">Share of workers</th><th class="px-3 py-3 text-right">Failed</th>
                    <th class="px-3 py-3 text-right">Queued</th><th class="px-3 py-3 text-right">Database</th><th class="px-5 py-3">Load</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in companies" :key="c.id" :data-company="c.slug">
                    <td class="px-5 py-3"><div class="font-medium">{{ c.name }}</div><div class="text-xs text-slate-400">{{ num(c.contacts) }} contacts <span v-if="c.status !== 'active'"> · suspended</span></div></td>
                    <td class="px-3 py-3"><SeatMeter :used="c.users" :max="c.max_users" compact /></td>
                    <td class="px-3 py-3 text-right tabular-nums">{{ num(c.activities) }}</td>
                    <td class="px-3 py-3 text-right tabular-nums">{{ c.automations_active }}<span class="text-slate-400"> / {{ c.automations_total }}</span><div class="text-xs text-slate-400">{{ num(c.automation_runs) }} runs · {{ secs(c.automation_seconds) }}</div></td>
                    <td class="px-3 py-3 text-right tabular-nums">{{ num(c.jobs) }}</td>
                    <td class="px-3 py-3 text-right tabular-nums">{{ secs(c.job_seconds) }}</td>
                    <td class="px-3 py-3">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-24 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="BAR[c.level]" :style="{ width: `${Math.max(c.share > 0 ? 3 : 0, c.share * 100)}%` }" /></div>
                            <span class="w-10 text-xs tabular-nums text-slate-500">{{ pct(c.share) }}</span>
                        </div>
                    </td>
                    <td class="px-3 py-3 text-right tabular-nums" :class="c.jobs_failed ? 'font-medium text-red-600' : 'text-slate-400'">{{ c.jobs_failed }}</td>
                    <td class="px-3 py-3 text-right tabular-nums">{{ num(c.queued) }}</td>
                    <td class="px-3 py-3 text-right tabular-nums">{{ mb(c.db_mb) }}</td>
                    <td class="px-5 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="LEVEL[c.level][1]" :data-level="c.level">{{ LEVEL[c.level][0] }}</span></td>
                </tr>
                <tr v-if="!companies.length"><td colspan="11" class="px-5 py-10 text-center text-slate-400">No companies yet.</td></tr>
            </tbody>
        </table>
    </section>
</template>
