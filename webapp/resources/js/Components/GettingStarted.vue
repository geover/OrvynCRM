<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Check, X } from 'lucide-vue-next';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

// A new workspace's first steps, on the administrators' dashboard until they are all done or somebody hides it (for the whole company).
const props = defineProps({ setup: { type: Object, required: true } });
const emit = defineEmits(['hide']);
const percent = computed(() => Math.round((props.setup.done / props.setup.total) * 100));
const next = computed(() => props.setup.steps.find((s) => !s.done));
const hide = () => run(async () => { await api.post('/dashboard/setup/dismiss'); emit('hide'); });
</script>

<template>
    <section class="card mb-5 p-5" aria-labelledby="gs-title" data-testid="getting-started">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="gs-title" class="text-sm font-semibold">Get your workspace ready</h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ setup.done }} of {{ setup.total }} done. These take a few minutes each and you can do them in any order.</p>
            </div>
            <div class="flex items-center gap-2">
                <Link v-if="next" :href="next.href" class="btn-primary !py-1.5 text-xs" data-testid="gs-next">Next: {{ next.title }}</Link>
                <button type="button" class="btn-ghost !p-1.5" title="Hide this" aria-label="Hide the checklist" data-testid="gs-hide" @click="hide"><X class="size-4" /></button>
            </div>
        </div>
        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" :aria-label="`${setup.done} of ${setup.total} steps done`"><div class="h-full rounded-full bg-brand-600 transition-all" :style="{ width: `${percent}%` }" /></div>
        <ul class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            <li v-for="s in setup.steps" :key="s.key">
                <Link :href="s.href" class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-2.5 transition hover:border-brand-500" :class="s.done && 'bg-slate-50/60'" :data-step="s.key" :data-done="s.done">
                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border" :class="s.done ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300'"><Check v-if="s.done" class="size-3" /></span>
                    <span class="min-w-0"><span class="block text-sm font-medium" :class="s.done && 'text-slate-400 line-through'">{{ s.title }}</span><span class="block text-xs text-slate-500">{{ s.text }}</span></span>
                </Link>
            </li>
        </ul>
    </section>
</template>
