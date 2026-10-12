<script setup>
import { router } from '@inertiajs/vue3';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { saved } from '@/lib/visit';

// One row per data object, one switch per background service that can be switched for a single data object.
const props = defineProps({ company: { type: Object, required: true }, services: { type: Object, required: true } });

const toggle = (object, service) => router.patch(`/platform/companies/${props.company.id}/services/objects/${object.id}`, { service, enabled: !object.services[service].enabled }, saved());
</script>

<template>
    <section class="card p-5" aria-labelledby="co-object-services" data-testid="object-services">
        <h2 id="co-object-services" class="text-sm font-semibold">Background services per data object</h2>
        <p class="mb-2 text-xs text-slate-400">Every data object has its own switches, so one object's background work can be stopped without touching the others. A service also stops for every object while it is switched off for the whole company above. Work already started finishes.</p>
        <ul class="mb-3 space-y-0.5 text-xs text-slate-500"><li v-for="s in services.object_catalog" :key="s.key"><strong class="font-medium text-slate-600">{{ s.label }}</strong>: {{ s.object_text }}</li></ul>
        <div class="max-h-[28rem] overflow-auto rounded-lg border border-slate-200">
            <table class="w-full text-left text-sm" aria-label="Background services per data object">
                <thead class="sticky top-0 z-10 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Data object</th>
                        <th v-for="s in services.object_catalog" :key="s.key" class="px-3 py-2 text-center font-medium" :title="s.object_text">
                            {{ s.label }}<span v-if="!services.states[s.key].enabled" class="block text-[10px] font-normal normal-case tracking-normal text-amber-700">off for the company</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="o in services.objects" :key="o.id" :data-object-service="o.name">
                        <td class="px-4 py-1.5"><span class="font-medium">{{ o.name }}</span><span class="ml-2 text-xs text-slate-400">{{ o.automations }} {{ o.automations === 1 ? 'automation' : 'automations' }}</span></td>
                        <td v-for="s in services.object_catalog" :key="s.key" class="px-3 py-1.5 text-center">
                            <ToggleSwitch small :on="o.services[s.key].enabled" :dimmed="!services.states[s.key].enabled" :label="`${s.label} of ${o.name}`" :title="s.object_text" :data-service="s.key" data-testid="object-service-switch" @toggle="toggle(o, s.key)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
