<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { Pause, Play, RefreshCw } from 'lucide-vue-next';
import SettingsShell from '@/Components/SettingsShell.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { confirmDialog, fmtRelative, toast } from '@/lib/ui';

const props = defineProps({ catalog: Array, companies: Array, queue: Object });
const page = usePage();

const done = (msg) => ({ preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? msg) });
const set = (company, service, enabled) => router.patch('/platform/services', { company, service, enabled }, done('Saved'));
const allOn = (c) => props.catalog.every((s) => c.services[s.key].enabled);
const allOff = (c) => props.catalog.every((s) => !c.services[s.key].enabled);
const columnOn = (key) => props.companies.every((c) => c.services[key].enabled);

async function pauseAll(c) {
    if (!(await confirmDialog({ title: `Pause everything for ${c.name}?`, message: 'No scheduled work, automations or message sending will run for this company until you resume it. Their data and sign-ins are not affected.', confirmLabel: 'Pause all', danger: true }))) return;
    set(c.id, '*', false);
}
async function pauseColumn(s) {
    if (!(await confirmDialog({ title: `Stop "${s.label}" for every company?`, message: 'It stays off until you switch it back on.', confirmLabel: 'Stop for all', danger: true }))) return;
    set('*', s.key, false);
}
const run = (c) => router.post(`/platform/companies/${c.id}/services/run`, {}, done('Run queued'));
</script>

<template>
    <Head title="Background services" />
    <SettingsShell title="Background services" subtitle="What runs in the background for each company. Switch a service off to pause it for that company only; due work waits and is picked up when you switch it back on.">

    <section class="mb-6 grid gap-3 sm:grid-cols-3" aria-label="Queue">
        <div class="card p-4"><div class="text-xs uppercase tracking-wide text-slate-500">Queue driver</div><div class="mt-1 text-lg font-semibold">{{ queue.driver || 'unknown' }}</div></div>
        <div class="card p-4"><div class="text-xs uppercase tracking-wide text-slate-500">Waiting / running</div>
            <div v-if="queue.queues.length" class="mt-1 space-y-0.5 text-sm"><div v-for="q in queue.queues" :key="q.name" class="flex justify-between"><span class="text-slate-600">{{ q.name }}</span><span>{{ q.pending }} / {{ q.running }}</span></div></div>
            <div v-else class="mt-1 text-sm text-slate-500">{{ queue.driver === 'database' ? 'Nothing waiting' : 'Not available for this driver' }}</div></div>
        <div class="card p-4"><div class="text-xs uppercase tracking-wide text-slate-500">Failed jobs</div><div class="mt-1 text-lg font-semibold" :class="queue.failed ? 'text-amber-600' : ''">{{ queue.failed }}</div></div>
    </section>
    <p class="mb-4 text-xs text-slate-500">The queue workers and the scheduler are server processes (Supervisor / Docker, see <code>deploy/supervisor/orvyn-workers.conf</code>). Starting or stopping those processes is done on the server; the switches below pause a single company without touching them.</p>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm" aria-label="Background services per company">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-5 py-3">Company</th>
                    <th v-for="s in catalog" :key="s.key" class="px-4 py-3 align-top">
                        <div class="normal-case tracking-normal text-slate-700">{{ s.label }}</div>
                        <div class="mt-0.5 text-[11px] font-normal normal-case tracking-normal text-slate-400">{{ s.schedule }}</div>
                        <button v-if="companies.length > 1" class="mt-1 text-[11px] font-normal normal-case tracking-normal text-brand-700 hover:underline" :data-all="s.key" @click="columnOn(s.key) ? pauseColumn(s) : set('*', s.key, true)">{{ columnOn(s.key) ? 'Stop for all' : 'Start for all' }}</button>
                    </th>
                    <th class="px-4 py-3">Waiting</th>
                    <th />
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in companies" :key="c.id" :data-company="c.slug">
                    <td class="px-5 py-3"><div class="font-medium">{{ c.name }}</div><div class="text-xs text-slate-400">{{ c.slug }}<span v-if="c.status !== 'active'" class="badge ml-2 !bg-amber-50 !text-amber-700">suspended</span></div></td>
                    <td v-for="s in catalog" :key="s.key" class="px-4 py-3">
                        <ToggleSwitch :on="c.services[s.key].enabled" :label="`${s.label} for ${c.name}`" :data-service="s.key" @toggle="set(c.id, s.key, !c.services[s.key].enabled)" />
                        <div class="mt-1 text-[11px] text-slate-400" :title="c.services[s.key].last_run_at">{{ c.services[s.key].last_run_at ? `ran ${fmtRelative(c.services[s.key].last_run_at)}` : 'not run yet' }}</div>
                    </td>
                    <td class="px-4 py-3 tabular-nums">{{ c.queued }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        <button class="btn-ghost !py-1.5" :disabled="c.status !== 'active'" :title="`Run ${c.name}'s scheduled work now`" @click="run(c)"><RefreshCw class="size-4" /> Run now</button>
                        <button v-if="!allOff(c)" class="btn-ghost !py-1.5" @click="pauseAll(c)"><Pause class="size-4" /> Pause all</button>
                        <button v-else class="btn-ghost !py-1.5" @click="set(c.id, '*', true)"><Play class="size-4" /> Resume all</button>
                    </td>
                </tr>
                <tr v-if="!companies.length"><td :colspan="catalog.length + 3" class="px-5 py-10 text-center text-slate-400">No companies yet.</td></tr>
            </tbody>
        </table>
    </div>
    <ul class="mt-4 space-y-1 text-xs text-slate-500"><li v-for="s in catalog" :key="s.key"><strong class="font-medium text-slate-600">{{ s.label }}</strong>: {{ s.text }}</li></ul>
</SettingsShell>
</template>
