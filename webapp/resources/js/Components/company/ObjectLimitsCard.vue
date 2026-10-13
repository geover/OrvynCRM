<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import SeatMeter from '@/Components/SeatMeter.vue';
import { saved } from '@/lib/visit';

// How many data objects the company may have, and how many automations each data object may have: the company default, or a
// number set for one object (a request that was approved, or typed here).
const props = defineProps({ company: { type: Object, required: true }, limits: { type: Object, required: true } });

const draft = reactive({});                                    // typed but not yet saved, by data object id
const url = (object) => `/platform/companies/${props.company.id}/object-limits/${object.id}`;
const done = (object, message) => saved(message, { onSuccess: () => delete draft[object.id] });
const save = (object) => router.put(url(object), { max_automations: draft[object.id] ?? object.max }, done(object, 'Saved'));
const reset = (object) => router.delete(url(object), done(object, 'Reset'));
</script>

<template>
    <section class="card p-5" aria-labelledby="co-limits" data-testid="limits-card">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="co-limits" class="text-sm font-semibold">Data objects and automations</h2>
                <p class="text-xs text-slate-400">Automations allowed per data object: {{ company.max_automations_per_object }} unless set below. Change the defaults with Edit on the Companies page.</p>
            </div>
            <SeatMeter :used="limits.data_objects.used" :max="limits.data_objects.max" unit="data objects" compact />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-2 pr-4 font-medium">Data object</th><th class="py-2 pr-4 font-medium">Automations</th><th class="py-2 font-medium">Allowed</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="o in limits.objects" :key="o.id" :data-object-limit="o.name">
                        <td class="py-2 pr-4 font-medium">{{ o.name }}</td>
                        <td class="py-2 pr-4"><SeatMeter :used="o.used" :max="o.max" unit="automations" compact /></td>
                        <td class="py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <input v-model.number="draft[o.id]" type="number" min="1" max="100000" class="input !w-24 !py-1" :placeholder="String(o.max)" :aria-label="`Automations allowed for ${o.name}`" data-testid="object-limit-input" />
                                <button class="btn-outline !py-1" :disabled="draft[o.id] === undefined || draft[o.id] === ''" data-testid="object-limit-save" @click="save(o)">Save</button>
                                <button v-if="o.custom" class="btn-ghost !py-1 text-slate-500" data-testid="object-limit-reset" @click="reset(o)">Use the default</button>
                                <span v-if="o.custom" class="badge">custom</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!limits.objects.length"><td colspan="3" class="py-6 text-center text-slate-400">No data objects yet.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
