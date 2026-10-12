<script setup>
import { computed } from 'vue';

// Horizontal bars for a ranking: items: [{ label, value, hint?, color?, badge? }]
const props = defineProps({
    items: { type: Array, required: true },
    format: { type: Function, default: (v) => new Intl.NumberFormat().format(v) },
    empty: { type: String, default: 'Nothing to show yet' },
    color: { type: String, default: '#6366f1' },
});
const max = computed(() => Math.max(1, ...props.items.map((i) => i.value)));
</script>

<template>
    <ul v-if="items.length" class="space-y-3">
        <li v-for="item in items" :key="item.label">
            <div class="flex items-baseline justify-between gap-3 text-sm"><span class="min-w-0 truncate font-medium">{{ item.label }}<span v-if="item.badge" class="ml-2 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="item.badge.cls">{{ item.badge.text }}</span></span><span class="shrink-0 tabular-nums text-slate-600">{{ format(item.value) }}</span></div>
            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100" role="presentation"><div class="h-full rounded-full transition-[width] duration-500" :style="{ width: `${Math.max(2, (item.value / max) * 100)}%`, background: item.color ?? color }" /></div>
            <div v-if="item.hint" class="mt-0.5 text-xs text-slate-400">{{ item.hint }}</div>
        </li>
    </ul>
    <p v-else class="py-8 text-center text-sm text-slate-400">{{ empty }}</p>
</template>
