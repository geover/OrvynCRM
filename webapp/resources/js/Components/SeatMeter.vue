<script setup>
import { computed } from 'vue';

// "3 of 5 users" with a bar that warms up as the limit gets close.
const props = defineProps({ used: { type: Number, required: true }, max: { type: Number, default: null }, compact: Boolean, unit: { type: String, default: 'users' } });
// a tier with unlimited users is stored as a huge number: show it as unlimited
const limit = computed(() => (props.max !== null && props.max >= 999999 ? null : props.max));
const ratio = computed(() => (limit.value ? Math.min(1, props.used / limit.value) : 0));
const tone = computed(() => (limit.value && props.used >= limit.value ? 'bg-red-500' : ratio.value >= 0.8 ? 'bg-amber-500' : 'bg-brand-600'));
</script>

<template>
    <div class="min-w-0" data-testid="seat-meter">
        <div class="flex items-baseline gap-1.5" :class="compact ? 'text-sm' : ''"><span class="font-semibold tabular-nums" :class="compact ? '' : 'text-lg'">{{ used }}</span><span class="text-slate-400">of</span><span class="font-semibold tabular-nums" :class="compact ? '' : 'text-lg'">{{ limit ?? '∞' }}</span><span class="text-slate-500">{{ unit }}</span></div>
        <div v-if="limit" class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100" :class="compact ? 'w-24' : 'w-48'" role="progressbar" :aria-valuenow="used" :aria-valuemin="0" :aria-valuemax="limit" :aria-label="`${used} of ${limit} ${unit}`"><div class="h-full rounded-full transition-[width] duration-500" :class="tone" :style="{ width: `${Math.max(used ? 4 : 0, ratio * 100)}%` }" /></div>
    </div>
</template>
