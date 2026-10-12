<script setup>
import { computed } from 'vue';

// A share-of-total ring with a legend: items: [{ label, value, color }]
const props = defineProps({
    items: { type: Array, required: true },
    format: { type: Function, default: (v) => new Intl.NumberFormat().format(v) },
    centerLabel: { type: String, default: '' },
    empty: { type: String, default: 'Nothing to show yet' },
});
const R = 52;
const C = 2 * Math.PI * R;
const total = computed(() => props.items.reduce((a, i) => a + i.value, 0));
const segments = computed(() => {
    let offset = 0;
    return props.items.filter((i) => i.value > 0).map((i) => {
        const len = (i.value / total.value) * C;
        const seg = { ...i, len, offset };
        offset += len;
        return seg;
    });
});
</script>

<template>
    <div v-if="total > 0" class="flex flex-col items-center gap-4">
        <svg viewBox="0 0 140 140" class="size-36 shrink-0 -rotate-90" role="img" :aria-label="centerLabel || 'Share of total'">
            <circle cx="70" cy="70" :r="R" fill="none" class="stroke-slate-100" stroke-width="18" />
            <circle v-for="s in segments" :key="s.label" cx="70" cy="70" :r="R" fill="none" :stroke="s.color" stroke-width="18" :stroke-dasharray="`${s.len} ${C - s.len}`" :stroke-dashoffset="-s.offset" />
        </svg>
        <ul class="w-full min-w-0 space-y-1.5 text-sm">
            <li v-for="i in items" :key="i.label" class="flex items-center gap-2"><span class="size-2.5 shrink-0 rounded-sm" :style="{ background: i.color }" /><span class="min-w-0 flex-1 truncate">{{ i.label }}</span><span class="tabular-nums text-slate-500">{{ Math.round((i.value / total) * 100) }}%</span></li>
        </ul>
    </div>
    <p v-else class="py-8 text-center text-sm text-slate-400">{{ empty }}</p>
</template>
