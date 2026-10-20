<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ArrowDown, ArrowUp, ArrowUpDown, Columns3, X } from 'lucide-vue-next';
import Dropdown from '@/Components/Dropdown.vue';
import { useStored } from '@/lib/stored';

/**
 * The shared list table: optional row selection with a bulk-action bar, a column chooser remembered per browser,
 * a sticky header, skeleton rows while loading and a card layout on phones.
 *
 * columns: [{ key, label, hideBelow?: 'lg'|'xl'|'2xl' (hidden on narrower desktops), title?: true (primary column, always shown), off?: true (hidden by default) }]
 * Sorting: a column with `sort: '<api key>'` has a clickable header (ascending, then descending, then ascending again); `sort` (v-model:sort) is the current
 * order as the API takes it, '-key' for descending.
 * Cell content: slot `cell-<key>` ({row}); default is row[key]. Row actions: slot `actions` ({row}). Bulk actions: slot `bulk`.
 * `display="cards"` shows the rows as a grid of cards (slot `card`: {row, selected, toggle}) instead of the table; the toolbar, the bulk bar and the footer stay.
 * The shown columns can be read and set from outside (a saved view remembers them): `getColumns()` gives the keys shown, `setColumns(keys)` shows those (the title column always), `resetColumns()` goes back to the default ones.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    loading: Boolean,
    selectable: Boolean,
    selected: { type: Array, default: () => [] },
    storageKey: { type: String, default: 'table' },
    emptyText: { type: String, default: 'Nothing here yet.' },
    rowClickable: Boolean,
    noun: { type: String, default: 'item' },
    sort: { type: String, default: '' },
    display: { type: String, default: 'table' },        // table | cards
    contained: Boolean,            // inside a pop-up: the header sticks to the top of the pop-up and the card does not stretch to the window's bottom
});
const emit = defineEmits(['update:selected', 'row-click', 'update:sort']);
const stick = computed(() => (props.contained ? 'top-0' : 'top-[65px]'));
const direction = (c) => (props.sort === c.sort ? 'ascending' : props.sort === `-${c.sort}` ? 'descending' : 'none');
const sortBy = (c) => emit('update:sort', props.sort === c.sort ? `-${c.sort}` : c.sort);

const hidden = useStored(`cols:${props.storageKey}`, props.columns.filter((c) => c.off).map((c) => c.key));
const visible = computed(() => props.columns.filter((c) => !hidden.value.includes(c.key)));
const toggleCol = (k) => { hidden.value = hidden.value.includes(k) ? hidden.value.filter((x) => x !== k) : [...hidden.value, k]; };
const resetCols = () => { hidden.value = props.columns.filter((c) => c.off).map((c) => c.key); };

const shownKeys = () => props.columns.filter((c) => c.title || !hidden.value.includes(c.key)).map((c) => c.key);
const setColumns = (keys) => { if (Array.isArray(keys)) hidden.value = props.columns.filter((c) => !c.title && !keys.includes(c.key)).map((c) => c.key); };
defineExpose({ getColumns: shownKeys, setColumns, resetColumns: () => resetCols() });

const allIds = computed(() => props.rows.map((r) => r.id));
const allOn = computed(() => allIds.value.length > 0 && allIds.value.every((id) => props.selected.includes(id)));
const toggleAll = () => emit('update:selected', allOn.value ? [] : [...allIds.value]);
const toggleOne = (id) => emit('update:selected', props.selected.includes(id) ? props.selected.filter((x) => x !== id) : [...props.selected, id]);
const band = (c) => ({ lg: 'md:max-lg:hidden', xl: 'md:max-xl:hidden', '2xl': 'md:max-2xl:hidden' })[c.hideBelow] ?? '';
const clickRow = (r) => props.rowClickable && emit('row-click', r);

// The card stretches to the bottom of the window (a short list no longer leaves an empty strip under it); on phones it is as tall as its cards.
const card = ref(null);
const fit = () => {
    if (!card.value || props.contained) return;
    const wide = window.matchMedia('(min-width: 768px)').matches;
    const top = card.value.getBoundingClientRect().top + window.scrollY;
    // exactly the room left in the window: the rows scroll inside the card and its footer (the pages) stays in view, so the page itself does not scroll
    card.value.style.height = wide ? `${Math.max(360, window.innerHeight - top - 24)}px` : '';
};
let watcher;
let frame = 0;
const refit = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(fit); };
onMounted(() => {
    fit();
    window.addEventListener('resize', fit);
    watcher = new ResizeObserver(refit);                       // numbers above the table arriving later, a bar appearing: the room changes
    watcher.observe(document.body);
});
onBeforeUnmount(() => { window.removeEventListener('resize', fit); watcher?.disconnect(); cancelAnimationFrame(frame); });
watch(() => [props.selected.length, props.loading], () => nextTick(fit), { flush: 'post' });
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2"><slot name="toolbar" /></div>
            <Dropdown v-if="display === 'table'" width="w-56">
                <template #trigger="{ toggle }"><button type="button" class="btn-outline" title="Choose columns" @click="toggle"><Columns3 class="size-4" /><span class="hidden sm:inline"> Columns</span></button></template>
                <p class="px-2 pb-1 pt-0.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Show columns</p>
                <label v-for="c in columns.filter((x) => !x.title)" :key="c.key" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-slate-50">
                    <input type="checkbox" class="rounded" :checked="!hidden.includes(c.key)" @change="toggleCol(c.key)" /> {{ c.label }}
                </label>
                <button type="button" class="mt-1 w-full rounded-md px-2 py-1.5 text-left text-xs text-slate-500 hover:bg-slate-50" @click="resetCols">Reset to default</button>
            </Dropdown>
        </div>

        <div v-if="selectable && selected.length" class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-brand-100 bg-brand-50 px-4 py-2.5 text-sm" role="toolbar" aria-label="Bulk actions">
            <span class="font-medium text-brand-700">{{ selected.length }} selected</span>
            <button v-if="selected.length < rows.length" type="button" class="text-brand-700 underline-offset-2 hover:underline" @click="toggleAll">Select all {{ rows.length }} loaded</button>
            <div class="ml-auto flex flex-wrap items-center gap-2"><slot name="bulk" :selected="selected" /></div>
            <button type="button" class="btn-ghost !px-2" title="Clear selection" @click="emit('update:selected', [])"><X class="size-4" /></button>
        </div>

        <div ref="card" class="card flex flex-col max-md:overflow-hidden">
            <div v-if="loading && !rows.length" class="divide-y divide-slate-100" aria-busy="true" aria-label="Loading">
                <div v-for="n in 6" :key="n" class="flex items-center gap-6 px-5 py-4">
                    <span class="h-4 w-1/5 animate-pulse rounded bg-slate-100" /><span class="h-4 w-1/4 animate-pulse rounded bg-slate-100" /><span class="hidden h-4 w-1/6 animate-pulse rounded bg-slate-100 md:block" /><span class="hidden h-4 w-1/6 animate-pulse rounded bg-slate-100 lg:block" />
                </div>
            </div>
            <div v-else-if="display === 'cards'" class="min-h-0 flex-1 overflow-y-auto p-4">
                <div v-if="rows.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    <template v-for="r in rows" :key="r.id"><slot name="card" :row="r" :selected="selected.includes(r.id)" :toggle="() => toggleOne(r.id)" /></template>
                </div>
                <div v-else-if="!loading" class="px-5 py-12 text-center text-slate-400"><slot name="empty">{{ emptyText }}</slot></div>
            </div>
            <div v-else class="table-scroll min-h-0 flex-1 max-md:max-h-none max-md:overflow-visible" :class="contained ? '!max-h-[52vh]' : 'md:!max-h-none'">
            <table class="w-full text-sm max-md:block">
                <thead class="max-md:hidden">
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <th v-if="selectable" :class="stick" class="sticky z-10 w-10 rounded-tl-xl border-b border-slate-100 bg-white px-5 py-3"><input type="checkbox" class="rounded" aria-label="Select all rows" :checked="allOn" @change="toggleAll" /></th>
                        <th v-for="c in visible" :key="c.key" :class="[band(c), stick]" class="sticky z-10 border-b border-slate-100 bg-white px-5 py-3 font-semibold" :aria-sort="c.sort ? direction(c) : undefined">
                            <button v-if="c.sort" type="button" class="-mx-1 inline-flex items-center gap-1 rounded px-1 uppercase tracking-wide hover:text-slate-800" :class="direction(c) !== 'none' && 'text-brand-700'" :data-sort="c.sort" :title="`Sort by ${c.label}`" @click="sortBy(c)">
                                {{ c.label }}<ArrowUp v-if="direction(c) === 'ascending'" class="size-3.5" /><ArrowDown v-else-if="direction(c) === 'descending'" class="size-3.5" /><ArrowUpDown v-else class="size-3 opacity-40" />
                            </button>
                            <template v-else>{{ c.label }}</template>
                        </th>
                        <th v-if="$slots.actions" :class="stick" class="sticky z-10 rounded-tr-xl border-b border-slate-100 bg-white px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 max-md:block">
                    <tr v-for="r in rows" :key="r.id" :class="[rowClickable && 'cursor-pointer', selected.includes(r.id) ? 'bg-brand-50/50' : 'hover:bg-slate-50']" class="group max-md:relative max-md:block max-md:px-4 max-md:py-3" @click="clickRow(r)">
                        <td v-if="selectable" class="w-10 px-5 py-3 max-md:absolute max-md:right-2 max-md:top-2 max-md:w-auto max-md:p-2" @click.stop><input type="checkbox" class="rounded" :aria-label="`Select ${noun}`" :checked="selected.includes(r.id)" @change="toggleOne(r.id)" /></td>
                        <td v-for="c in visible" :key="c.key" :class="[band(c), c.title ? 'max-md:pr-8 max-md:text-base' : 'max-md:text-slate-600 max-md:before:content-[attr(data-label)]']" :data-label="c.label"
                            class="px-5 py-3 max-md:block max-md:px-0 max-md:py-0.5 max-md:before:mr-2 max-md:before:text-xs max-md:before:text-slate-400 max-md:empty:hidden">
                            <slot :name="`cell-${c.key}`" :row="r">{{ r[c.key] }}</slot>
                        </td>
                        <td v-if="$slots.actions" class="whitespace-nowrap px-3 text-right max-md:block max-md:px-0 max-md:pt-2 max-md:text-left" @click.stop><slot name="actions" :row="r" /></td>
                    </tr>
                    <tr v-if="!rows.length && !loading" class="max-md:block"><td :colspan="visible.length + 2" class="px-5 py-12 text-center text-slate-400 max-md:block"><slot name="empty">{{ emptyText }}</slot></td></tr>
                </tbody>
            </table>
            </div>
            <div v-if="$slots.footer" class="shrink-0" data-testid="table-footer"><slot name="footer" /></div>
        </div>
    </div>
</template>
