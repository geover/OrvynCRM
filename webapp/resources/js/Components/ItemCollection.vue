<script setup>
import { computed, ref } from 'vue';
import { LayoutGrid, LayoutList, List, Search } from 'lucide-vue-next';
import { useStored } from '@/lib/stored';

// A collection of things an administrator manages (forms, queries, templates, endpoints...) in the layout the person prefers, kept in this browser:
//   'detailed' a card per item, with its details underneath     'list' one slim line per item     'boxes' a grid of cards
// The page says what an item is made of through slots; this draws them. Slots, each given { item }:
//   title (required), badges, meta (a line of details), actions (buttons), icon (instead of the common one), empty (under the empty message), toolbar (extra filters, before the switch)
const props = defineProps({
    items: { type: Array, default: null },                      // null while loading
    storageKey: { type: String, required: true },
    icon: { type: Object, required: true },
    tone: { type: String, default: 'bg-indigo-100 text-indigo-700' },
    searchText: { type: Function, default: null },               // (item) => the words a search looks in; no function: no search box
    searchLabel: { type: String, default: 'Find…' },
    attrs: { type: Function, default: null },                    // (item) => extra attributes for the item's element (test hooks)
    emptyTitle: { type: String, default: 'Nothing here yet' },
    emptyText: { type: String, default: '' },
    defaultLayout: { type: String, default: 'detailed' },
});

const layout = useStored(`${props.storageKey}:layout`, props.defaultLayout);
const LAYOUTS = [['detailed', LayoutList, 'Detailed'], ['list', List, 'List'], ['boxes', LayoutGrid, 'Boxes']];
const q = ref('');
const shown = computed(() => {
    const term = q.value.trim().toLowerCase();
    return (props.items ?? []).filter((i) => !term || !props.searchText || props.searchText(i).toLowerCase().includes(term));
});
</script>

<template>
    <div data-testid="item-collection">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div v-if="searchText" class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" type="search" class="input !w-60 !pl-9" :placeholder="searchLabel" :aria-label="searchLabel" data-testid="collection-search" /></div>
            <slot name="toolbar" />
            <div class="ml-auto inline-flex rounded-xl border border-slate-200 p-1 text-sm" role="group" aria-label="Layout">
                <button v-for="[v, icon, l] in LAYOUTS" :key="v" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-medium transition" :class="layout === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :aria-pressed="layout === v" :title="l" :data-testid="`layout-${v}`" @click="layout = v"><component :is="icon" class="size-4" /><span class="hidden sm:inline">{{ l }}</span></button>
            </div>
        </div>

        <div v-if="items === null" class="text-sm text-slate-400">Loading…</div>

        <div v-else-if="shown.length && layout === 'detailed'" class="space-y-3">
            <article v-for="(item, n) in shown" :key="item.id ?? n" class="card msg-row p-4" v-bind="attrs?.(item)">
                <div class="flex flex-wrap items-center gap-4">
                    <slot name="icon" :item="item"><span class="grid size-11 shrink-0 place-items-center rounded-xl" :class="tone"><component :is="icon" class="size-5" /></span></slot>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2"><span class="min-w-0 truncate font-semibold"><slot name="title" :item="item" /></span><slot name="badges" :item="item" /></div>
                        <div v-if="$slots.meta" class="mt-0.5 text-xs text-slate-400"><slot name="meta" :item="item" /></div>
                    </div>
                    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-1"><slot name="actions" :item="item" /></div>
                </div>
            </article>
        </div>

        <div v-else-if="shown.length && layout === 'list'" class="card divide-y divide-slate-100 overflow-hidden">
            <div v-for="(item, n) in shown" :key="item.id ?? n" class="msg-row flex flex-wrap items-center gap-3 px-4 py-2 text-sm transition hover:bg-slate-50" v-bind="attrs?.(item)">
                <slot name="icon" :item="item"><span class="grid size-8 shrink-0 place-items-center rounded-lg" :class="tone"><component :is="icon" class="size-4" /></span></slot>
                <span class="min-w-0 flex-1 truncate font-medium"><slot name="title" :item="item" /></span>
                <span class="flex flex-wrap items-center gap-1.5"><slot name="badges" :item="item" /></span>
                <div v-if="$slots.actions" class="flex shrink-0 items-center gap-1"><slot name="actions" :item="item" /></div>
            </div>
        </div>

        <div v-else-if="shown.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="(item, n) in shown" :key="item.id ?? n" class="card msg-row flex flex-col p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md" v-bind="attrs?.(item)">
                <div class="flex items-start gap-3">
                    <slot name="icon" :item="item"><span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="tone"><component :is="icon" class="size-5" /></span></slot>
                    <div class="min-w-0 flex-1"><div class="font-semibold leading-snug"><slot name="title" :item="item" /></div><div class="mt-1 flex flex-wrap items-center gap-1.5"><slot name="badges" :item="item" /></div></div>
                </div>
                <p v-if="$slots.meta" class="mt-3 text-xs text-slate-400"><slot name="meta" :item="item" /></p>
                <div v-if="$slots.actions" class="mt-auto flex flex-wrap items-center justify-end gap-1 pt-3"><slot name="actions" :item="item" /></div>
            </article>
        </div>

        <div v-else class="card msg-row px-6 py-14 text-center text-slate-500" data-testid="collection-empty">
            <span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl" :class="tone"><component :is="icon" class="size-8" /></span>
            <p class="font-semibold text-slate-700">{{ items.length ? 'Nothing matches' : emptyTitle }}</p>
            <p v-if="!items.length && emptyText" class="mx-auto mt-1 max-w-sm text-sm">{{ emptyText }}</p>
            <div v-if="!items.length && $slots.empty" class="mt-4"><slot name="empty" /></div>
        </div>
    </div>
</template>
