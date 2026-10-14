<script setup>
import { ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Pause, Play, RefreshCw } from 'lucide-vue-next';
import SettingsShell from '@/Components/SettingsShell.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';

const props = defineProps({ catalog: Array, companies: Array, queue: Object, schedule: { type: Array, default: () => [] }, processes: { type: Object, default: () => ({}) }, timezone: String });
const page = usePage();

// the Scheduled work table can be folded away; the choice is remembered in this browser
const KEY = 'platform.services.schedule.open';
const open = ref((() => { try { return localStorage.getItem(KEY) !== '0'; } catch { return true; } })());
const toggle = () => { open.value = !open.value; try { localStorage.setItem(KEY, open.value ? '1' : '0'); } catch { /* the choice just is not remembered */ } };

// changing how often one of the platform's own jobs runs
const editing = ref(null);
const draft = ref({ frequency: 'hourly', at: '00:20' });
const edit = (r) => { draft.value = { frequency: r.frequency.frequency, at: r.frequency.at }; editing.value = r.job; };
const saveSchedule = (job, reset = false) => router.patch(`/platform/services/schedule/${job}`, reset ? { reset: true } : draft.value, {
    preserveScroll: true,
    onSuccess: () => { editing.value = null; toast(page.props.flash.success ?? 'Saved', 'success'); },
    onError: (e) => toast(Object.values(e)[0] ?? 'Could not save', 'error'),
});

const done = (msg) => ({ preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? msg) });
const set = (company, service, enabled) => router.patch('/platform/services', { company, service, enabled }, done('Saved'));
// a service that waits for the company's details cannot be switched on, so it does not count for "all on" or "all off"
const usable = (c) => props.catalog.filter((s) => c.services[s.key].ready);
const allOn = (c) => usable(c).every((s) => c.services[s.key].enabled);
const allOff = (c) => usable(c).every((s) => !c.services[s.key].enabled);
const columnOn = (key) => props.companies.filter((c) => c.services[key].ready).every((c) => c.services[key].enabled);

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
    <section class="card mb-6" aria-labelledby="sv-sched" data-testid="schedule">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3" :class="open && 'border-b border-slate-100'">
            <h2 id="sv-sched" class="text-sm font-semibold"><button type="button" class="flex items-center gap-1.5 rounded-md hover:text-brand-700" :aria-expanded="open" aria-controls="sv-sched-body" data-testid="schedule-toggle" @click="toggle"><ChevronDown class="size-4 transition-transform" :class="!open && '-rotate-90'" aria-hidden="true" /> Scheduled work</button></h2>
            <span v-for="(p, name) in processes" :key="name" class="badge" :class="p.running ? '!bg-emerald-50 !text-emerald-700' : p.running === false ? '!bg-red-50 !text-red-700' : ''" :data-testid="`process-${name}`">{{ name }}: {{ p.running ? 'running' : p.running === false ? 'stopped' : 'unknown' }}<template v-if="p.seen"> (seen {{ p.seen }})</template></span>
            <span class="text-xs text-slate-500">Times are shown in your browser's time zone; the server runs on {{ timezone }}.</span>
        </div>
        <div v-show="open" id="sv-sched-body" class="max-h-80 overflow-auto" tabindex="0" aria-label="Scheduled work, scrollable" data-testid="schedule-body">
        <table class="w-full text-sm" aria-label="Scheduled work">
            <thead class="sticky top-0 z-10 border-b border-slate-100 bg-white text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2">What</th><th class="px-4 py-2">Schedule</th><th class="px-4 py-2">Last run</th><th class="px-4 py-2">Took</th><th class="px-4 py-2">Next run</th><th class="px-4 py-2"><span class="sr-only">Change</span></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="r in schedule" :key="r.label">
                    <td class="px-4 py-2 font-medium">{{ r.label }}</td>
                    <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ r.expression }}</td>
                    <td class="px-4 py-2"><template v-if="r.last_run"><span :class="r.ok ? '' : 'text-red-600'">{{ fmtDate(r.last_run, true) }}</span><span v-if="!r.ok" class="block text-xs text-red-600">Failed{{ r.error ? ': ' + r.error : '' }}</span></template><span v-else class="text-slate-400">not since the scheduler started recording</span></td>
                    <td class="px-4 py-2 text-slate-600">{{ r.seconds !== null ? r.seconds + ' s' : '' }}</td>
                    <td class="px-4 py-2">{{ fmtDate(r.next_run, true) }}</td>
                    <td class="px-4 py-2 text-right"><button v-if="r.job" type="button" class="btn-outline !px-2.5 !py-1 text-xs" :data-testid="`schedule-edit-${r.job}`" @click="editing === r.job ? (editing = null) : edit(r)">{{ editing === r.job ? 'Close' : 'Change' }}</button></td>
                </tr>
                <tr v-for="r in schedule.filter((x) => x.job && editing === x.job)" :key="r.job" class="bg-slate-50/60" :data-testid="`schedule-form-${r.job}`">
                    <td colspan="6" class="px-4 py-3">
                        <div class="flex flex-wrap items-end gap-3">
                            <label class="text-xs font-medium text-slate-600">How often
                                <select v-model="draft.frequency" class="input mt-1 block" :data-testid="`schedule-frequency-${r.job}`"><option v-for="c in r.frequency.choices" :key="c.value" :value="c.value">{{ c.label }}</option></select></label>
                            <label class="text-xs font-medium text-slate-600">{{ draft.frequency === 'daily' ? 'At (server time)' : 'Minute past the hour' }}
                                <input v-if="draft.frequency === 'daily'" v-model="draft.at" type="time" class="input mt-1 block" :data-testid="`schedule-at-${r.job}`" />
                                <select v-else-if="!draft.frequency.startsWith('every_') || draft.frequency.endsWith('hours')" :value="draft.at.slice(3)" class="input mt-1 block" @change="draft.at = '00:' + $event.target.value"><option v-for="m in ['00', '05', '10', '15', '20', '30', '40', '45', '50']" :key="m" :value="m">:{{ m }}</option></select>
                                <span v-else class="input mt-1 block !border-transparent !bg-transparent text-slate-500">every {{ draft.frequency === 'every_15_minutes' ? 15 : 30 }} minutes</span></label>
                            <button type="button" class="btn-primary" :data-testid="`schedule-save-${r.job}`" @click="saveSchedule(r.job)">Save</button>
                            <button v-if="!r.frequency.is_default" type="button" class="btn-outline" @click="saveSchedule(r.job, true)">Back to the usual</button>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Used by the scheduler from its next minute; nothing needs restarting. Running it more often only costs server time. Times use the server's time zone ({{ timezone }}).</p>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    </section>
    <p class="mb-4 text-xs text-slate-500">The queue workers and the scheduler are server processes (Supervisor / Docker, see <code>deploy/supervisor/orvyn-workers.conf</code>). Starting or stopping those processes is done on the server; the switches below pause a single company without touching them.</p>

    <div class="card table-scroll">
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
                        <ToggleSwitch :on="c.services[s.key].enabled" :disabled="!c.services[s.key].ready" :title="c.services[s.key].note ?? undefined" :label="`${s.label} for ${c.name}`" :data-service="s.key" @toggle="set(c.id, s.key, !c.services[s.key].enabled)" />
                        <div v-if="c.services[s.key].note" class="mt-1 max-w-[9rem] text-[11px] leading-tight text-amber-700" data-testid="waiting-details">Waiting for the company's details</div>
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
