<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

// The footer of a table with numbered pages: "1-25 of 1,234", the rows-per-page choice, Previous / page numbers / Next.
const props = defineProps({
    meta: { type: Object, default: null },        // {current_page, last_page, per_page, total, from, to}
    sizes: { type: Array, default: () => [25, 50, 100] },
    loading: Boolean,
});
const emit = defineEmits(['page', 'size']);

// 1 … 4 5 6 … 20: always the first, the last and the neighbours of the current page
const pages = computed(() => {
    const last = props.meta?.last_page ?? 1;
    const cur = props.meta?.current_page ?? 1;
    const set = new Set([1, last, cur - 1, cur, cur + 1].filter((n) => n >= 1 && n <= last));
    const out = [];
    let prev = 0;
    for (const n of [...set].sort((a, b) => a - b)) {
        if (n - prev > 1) out.push('…');
        out.push(n);
        prev = n;
    }
    return out;
});
const number = (n) => Number(n).toLocaleString();
</script>

<template>
    <nav v-if="meta && meta.total > 0" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-500" aria-label="Pagination" data-testid="pagination">
        <div class="flex items-center gap-3">
            <span data-testid="pagination-range">{{ number(meta.from) }}–{{ number(meta.to) }} of {{ number(meta.total) }}</span>
            <label class="flex items-center gap-1.5"><span class="max-sm:sr-only">Rows</span>
                <select class="input !w-auto !py-1 text-sm" :value="meta.per_page" aria-label="Rows per page" data-testid="pagination-size" @change="emit('size', Number($event.target.value))"><option v-for="s in sizes" :key="s" :value="s">{{ s }}</option></select>
            </label>
        </div>
        <div v-if="meta.last_page > 1" class="flex items-center gap-1">
            <button type="button" class="btn-ghost !px-2 !py-1" :disabled="loading || meta.current_page <= 1" aria-label="Previous page" @click="emit('page', meta.current_page - 1)"><ChevronLeft class="size-4" /></button>
            <template v-for="(p, i) in pages" :key="i">
                <span v-if="p === '…'" class="px-1" aria-hidden="true">…</span>
                <button v-else type="button" class="min-w-8 rounded-lg px-2 py-1 text-sm" :class="p === meta.current_page ? 'bg-brand-50 font-semibold text-brand-700' : 'hover:bg-slate-100'" :disabled="loading" :aria-current="p === meta.current_page ? 'page' : undefined" :aria-label="`Page ${p}`" @click="emit('page', p)">{{ p }}</button>
            </template>
            <button type="button" class="btn-ghost !px-2 !py-1" :disabled="loading || meta.current_page >= meta.last_page" aria-label="Next page" @click="emit('page', meta.current_page + 1)"><ChevronRight class="size-4" /></button>
        </div>
    </nav>
</template>
