<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Pause, Play, RefreshCw } from 'lucide-vue-next';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { confirmDialog, fmtRelative } from '@/lib/ui';
import { saved } from '@/lib/visit';

// The background services of ONE company: the same switches as Settings > Background services, for this company only.
const props = defineProps({ company: { type: Object, required: true }, services: { type: Object, required: true } });

const set = (service, enabled) => router.patch('/platform/services', { company: props.company.id, service, enabled }, saved());
const allOff = computed(() => props.services.catalog.every((s) => !props.services.states[s.key].enabled));
const runNow = () => router.post(`/platform/companies/${props.company.id}/services/run`, {}, saved('Run queued'));
async function pauseAll() {
    if (!(await confirmDialog({ title: `Pause everything for ${props.company.name}?`, message: 'No scheduled work, automations or message sending will run for this company until you resume it. Their data and sign-ins are not affected.', confirmLabel: 'Pause all', danger: true }))) return;
    set('*', false);
}
</script>

<template>
    <section class="card p-5" aria-labelledby="co-services" data-testid="services-card">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="co-services" class="text-sm font-semibold">Background services</h2>
                <p class="text-xs text-slate-400">What runs in the background for this company only. Work that falls due while a service is off waits and is picked up when you switch it on. {{ services.queued }} {{ services.queued === 1 ? 'job is' : 'jobs are' }} waiting. <Link href="/platform/services" class="underline">All companies</Link></p>
            </div>
            <div class="flex flex-wrap gap-1">
                <button class="btn-ghost !py-1.5" :disabled="company.status !== 'active'" data-testid="company-run-services" @click="runNow"><RefreshCw class="size-4" /> Run now</button>
                <button v-if="!allOff" class="btn-ghost !py-1.5" data-testid="company-pause-services" @click="pauseAll"><Pause class="size-4" /> Pause all</button>
                <button v-else class="btn-ghost !py-1.5" data-testid="company-resume-services" @click="set('*', true)"><Play class="size-4" /> Resume all</button>
            </div>
        </div>
        <ul class="divide-y divide-slate-100">
            <li v-for="s in services.catalog" :key="s.key" class="flex items-center justify-between gap-4 py-3" :data-service-row="s.key">
                <div class="min-w-0">
                    <div class="text-sm font-medium">{{ s.label }}</div>
                    <div class="text-xs text-slate-500">{{ s.text }}</div>
                    <div class="mt-0.5 text-[11px] text-slate-400" :title="services.states[s.key].last_run_at">{{ s.schedule }} · {{ services.states[s.key].last_run_at ? `ran ${fmtRelative(services.states[s.key].last_run_at)}` : 'not run yet' }}</div>
                </div>
                <ToggleSwitch :on="services.states[s.key].enabled" :label="`${s.label} for ${company.name}`" :data-service="s.key" @toggle="set(s.key, !services.states[s.key].enabled)" />
            </li>
        </ul>
    </section>
</template>
