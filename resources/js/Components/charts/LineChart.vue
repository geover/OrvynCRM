<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// A small dependency-free SVG chart: lines (with a soft fill) and/or bars over shared labels, hover tooltip, readable in
// light and dark. series: [{ name, color, data: number[], kind?: 'line' | 'bar' }]
const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Array, required: true },
    height: { type: Number, default: 220 },
    format: { type: Function, default: (v) => new Intl.NumberFormat().format(v) },
    title: { type: String, default: 'Chart' },
});

// The drawing is as wide as its box, so 1 unit is 1 pixel: text keeps one size in a narrow card and in a full-width one.
const root = ref(null);
const width = ref(640);
const W = computed(() => Math.max(280, width.value));
let observer;
onMounted(() => {
    width.value = root.value?.clientWidth || 640;
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(([entry]) => { width.value = Math.round(entry.contentRect.width) || width.value; });
        observer.observe(root.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());
const M = { l: 46, r: 12, t: 12, b: 28 };
const plotW = computed(() => W.value - M.l - M.r);
const plotH = computed(() => props.height - M.t - M.b);
const n = computed(() => props.labels.length);
const band = computed(() => plotW.value / Math.max(1, n.value));
const cx = (i) => M.l + (i + 0.5) * band.value;

const niceMax = (v) => {
    if (v <= 0) return 1;
    const p = 10 ** Math.floor(Math.log10(v));
    const m = v / p;
    return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p;
};
const yMax = computed(() => niceMax(Math.max(0, ...props.series.flatMap((s) => s.data))));
const y = (v) => M.t + plotH.value - (v / yMax.value) * plotH.value;
const ticks = computed(() => [0, 1, 2, 3, 4].map((i) => (yMax.value / 4) * i));
const step = computed(() => Math.max(1, Math.ceil(n.value / 8)));

const bars = computed(() => props.series.filter((s) => s.kind === 'bar'));
const lines = computed(() => props.series.filter((s) => s.kind !== 'bar'));
const barW = computed(() => Math.min(28, (band.value * 0.7) / Math.max(1, bars.value.length)));
const barX = (si, i) => cx(i) - (barW.value * bars.value.length) / 2 + si * barW.value;
const path = (s) => s.data.map((v, i) => `${i ? 'L' : 'M'}${cx(i).toFixed(1)} ${y(v).toFixed(1)}`).join(' ');
const area = (s) => `${path(s)} L${cx(n.value - 1).toFixed(1)} ${y(0)} L${cx(0).toFixed(1)} ${y(0)} Z`;

const hover = ref(null);
const empty = computed(() => props.series.every((s) => s.data.every((v) => !v)));
</script>

<template>
    <div ref="root" class="relative">
        <svg :viewBox="`0 0 ${W} ${height}`" :width="W" :height="height" class="block w-full" role="img" :aria-label="title" @mouseleave="hover = null">
            <g class="text-slate-500" font-size="11" fill="currentColor">
                <g v-for="t in ticks" :key="t">
                    <line :x1="M.l" :x2="W - M.r" :y1="y(t)" :y2="y(t)" class="stroke-slate-200" stroke-width="1" />
                    <text :x="M.l - 8" :y="y(t) + 4" text-anchor="end">{{ format(t) }}</text>
                </g>
                <text v-for="(l, i) in labels" v-show="i % step === 0" :key="i" :x="cx(i)" :y="height - 8" text-anchor="middle">{{ l }}</text>
            </g>

            <g v-for="(s, si) in bars" :key="`b${si}`"><rect v-for="(v, i) in s.data" :key="i" :x="barX(si, i)" :y="y(v)" :width="barW" :height="Math.max(0, y(0) - y(v))" rx="3" :fill="s.color" :opacity="hover === null || hover === i ? 1 : 0.45" /></g>
            <g v-for="(s, si) in lines" :key="`l${si}`">
                <path :d="area(s)" :fill="s.color" opacity="0.12" />
                <path :d="path(s)" fill="none" :stroke="s.color" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <circle v-if="hover !== null" :cx="cx(hover)" :cy="y(s.data[hover])" r="4" :fill="s.color" stroke="white" stroke-width="2" />
            </g>
            <line v-if="hover !== null" :x1="cx(hover)" :x2="cx(hover)" :y1="M.t" :y2="M.t + plotH" class="stroke-slate-300" stroke-dasharray="3 3" />
            <rect v-for="(l, i) in labels" :key="`h${i}`" :x="M.l + i * band" :y="M.t" :width="band" :height="plotH" fill="transparent" @mouseenter="hover = i" />
            <text v-if="empty" :x="W / 2" :y="M.t + plotH / 2" text-anchor="middle" font-size="13" class="fill-slate-400">No data in this period yet</text>
        </svg>

        <div v-if="hover !== null" class="pointer-events-none absolute top-1 z-10 -translate-x-1/2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg" :style="{ left: `${Math.min(88, Math.max(12, (cx(hover) / W) * 100))}%` }">
            <div class="mb-1 font-medium text-slate-700">{{ labels[hover] }}</div>
            <div v-for="s in series" :key="s.name" class="flex items-center gap-2 text-slate-600"><span class="size-2 rounded-full" :style="{ background: s.color }" />{{ s.name }}: <span class="font-medium text-slate-800">{{ format(s.data[hover]) }}</span></div>
        </div>

        <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500" aria-hidden="true"><li v-for="s in series" :key="s.name" class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.name }}</li></ul>
        <div class="sr-only"><table><caption>{{ title }}</caption><thead><tr><th>Period</th><th v-for="s in series" :key="s.name">{{ s.name }}</th></tr></thead><tbody><tr v-for="(l, i) in labels" :key="i"><td>{{ l }}</td><td v-for="s in series" :key="s.name">{{ s.data[i] }}</td></tr></tbody></table></div>
    </div>
</template>
