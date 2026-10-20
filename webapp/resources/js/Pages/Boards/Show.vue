<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Filter, ListTodo, RefreshCw, Search, X } from 'lucide-vue-next';
import FilterBuilder from '@/Components/FilterBuilder.vue';
import PageHeader from '@/Components/PageHeader.vue';
import BoardCardModal from '@/Components/BoardCardModal.vue';
import { api } from '@/lib/api';
import { withColors } from '@/lib/boards';
import { fmtDate, fmtRelative, toastError } from '@/lib/ui';

const props = defineProps({ boardId: Number });
const data = ref(null);
const dragging = ref(null);      // {card, from}
const over = ref(null);
const q = ref('');
const filter = ref({ match: 'all', rules: [] });      // the person's own conditions (the same builder as the item lists)
const showFilter = ref(false);
const updated = ref('');                              // '' | today | 7d | 30d
const withContacts = ref('');                         // '' | with | without
const landed = ref(null);        // the card that has just been dropped: it animates into place
const scroller = ref(null);       // the row of columns, which scrolls sideways
const opened = ref(null);        // the id of the card shown in the wide pop-up
const refreshing = ref(false);

const activeRules = computed(() => (filter.value.rules ?? []).filter((r) => r.rules || (r.field && (['empty', 'not_empty'].includes(r.op) || (r.value !== '' && r.value !== null && !(Array.isArray(r.value) && !r.value.length))))));
const narrowedBy = computed(() => (q.value.trim() ? 1 : 0) + activeRules.value.length + (updated.value ? 1 : 0) + (withContacts.value ? 1 : 0));
let latest = 0;
/** Asks the server for the board: the search and the conditions are applied there, so they cover every item, not only the cards shown. */
async function load() {
    const params = { q: q.value.trim() || undefined, updated: updated.value || undefined, contacts: withContacts.value || undefined };
    if (activeRules.value.length) {
        params['filter[match]'] = filter.value.match;
        activeRules.value.forEach((r, i) => {
            if (r.rules) return;
            params[`filter[rules][${i}][field]`] = r.field; params[`filter[rules][${i}][op]`] = r.op;
            Array.isArray(r.value) ? r.value.forEach((x, j) => (params[`filter[rules][${i}][value][${j}]`] = x)) : (params[`filter[rules][${i}][value]`] = r.value);
        });
    }
    const mine = ++latest;
    refreshing.value = true;
    try {
        const res = await api.get(`/boards/${props.boardId}`, params);
        if (mine === latest) data.value = res;                         // an older answer never replaces a newer one
    } catch (e) { if (mine === latest) toastError(e); } finally { if (mine === latest) refreshing.value = false; }
}
onMounted(load);
/** The columns fill the window from where they start to its bottom edge, so their sideways scroll bar is always in view (the page itself does not need to scroll). */
function fit() {
    if (!scroller.value) return;
    const top = scroller.value.getBoundingClientRect().top + window.scrollY;
    scroller.value.style.height = `${Math.max(384, window.innerHeight - top - 40)}px`;
}
const refit = () => nextTick(fit);
onMounted(() => window.addEventListener('resize', fit));
onBeforeUnmount(() => window.removeEventListener('resize', fit));
watch([data, showFilter, () => filter.value.rules.length], refit, { flush: 'post' });
let typing;
watch(q, () => { clearTimeout(typing); typing = setTimeout(load, 300); });
watch([updated, withContacts], load);
function clearAll() { q.value = ''; updated.value = ''; withContacts.value = ''; filter.value = { match: 'all', rules: [] }; }
watch(() => activeRules.value.length, (n, was) => { if (n === 0 && was > 0) load(); });          // removing the last condition shows everything again at once

const cardTitle = (card) => card.values[data.value.display[0]?.key] ?? `#${card.id}`;
const cols = computed(() => withColors(data.value?.columns));
const total = computed(() => cols.value.reduce((n, c) => n + c.total, 0));
const visible = (col) => col.cards;
const stageOf = (id) => cols.value.find((c) => c.cards.some((x) => x.id === id))?.key;
const openedCard = computed(() => cols.value.flatMap((c) => c.cards).find((x) => x.id === opened.value));

/** Moves a card to another column on the screen at once and saves it; the screen goes back if the server refuses. */
async function move(card, from, to) {
    if (from === to) return;
    const snapshot = JSON.stringify(data.value.columns);
    const a = data.value.columns.find((c) => c.key === from);
    const b = data.value.columns.find((c) => c.key === to);
    a.cards = a.cards.filter((c) => c.id !== card.id); a.total--; a.sum = null;
    b.cards.unshift(card); b.total++; b.sum = null;
    landed.value = card.id; setTimeout(() => { if (landed.value === card.id) landed.value = null; }, 700);          // it settles into its new column
    try { await api.patch(`/boards/${props.boardId}/cards/${card.id}`, { to }); load(); }
    catch (e) { data.value.columns = JSON.parse(snapshot); toastError(e); }
}
function start(e, card, from) {
    dragging.value = { card, from }; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(card.id));
    // The browser's own drag picture cannot be tilted reliably, so it is hidden and a copy of the card, tilted and lifted, follows the pointer instead.
    stopCarrying();
    const el = e.currentTarget;
    const box = el.getBoundingClientRect();
    const dx = e.clientX - box.left; const dy = e.clientY - box.top;
    const blank = new Image(); blank.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    e.dataTransfer.setDragImage(blank, 0, 0);
    const ghost = el.cloneNode(true);
    ghost.removeAttribute('data-testid'); ghost.removeAttribute('tabindex'); ghost.classList.remove('opacity-40');
    Object.assign(ghost.style, { position: 'fixed', zIndex: '60', width: `${box.width}px`, margin: '0', opacity: '1', pointerEvents: 'none', cursor: 'grabbing', transformOrigin: '50% 0', boxShadow: '0 16px 32px rgba(15, 23, 42, 0.3)' });
    const place = (x, y) => { ghost.style.left = `${x - dx}px`; ghost.style.top = `${y - dy}px`; ghost.style.transform = 'rotate(4deg)'; };
    place(e.clientX, e.clientY);
    document.body.appendChild(ghost);
    const pointer = { x: e.clientX, y: e.clientY };
    const follow = (ev) => { if (ev.clientX || ev.clientY) { place(ev.clientX, ev.clientY); pointer.x = ev.clientX; pointer.y = ev.clientY; } };
    // Near the edge of the board, or of the window, the page scrolls by itself (faster the closer to the edge), so every column can be reached while carrying.
    let frame = requestAnimationFrame(function scroll() {
        const bar = scroller.value?.getBoundingClientRect();
        const edge = 90; const speed = (d) => Math.ceil(Math.min(1, (edge - d) / edge) * 22);
        if (bar && pointer.y > bar.top - 40 && pointer.y < bar.bottom + 40) {
            if (pointer.x < bar.left + edge) scroller.value.scrollLeft -= speed(Math.max(0, pointer.x - bar.left));
            else if (pointer.x > bar.right - edge) scroller.value.scrollLeft += speed(Math.max(0, bar.right - pointer.x));
        }
        if (pointer.y < 70) window.scrollBy(0, -speed(Math.max(0, pointer.y - 10)));
        else if (pointer.y > window.innerHeight - 70) window.scrollBy(0, speed(Math.max(0, window.innerHeight - pointer.y - 10)));
        frame = requestAnimationFrame(scroll);
    });
    // The card that was picked up leaves the screen when it is dropped in another column, so its own "dragend" never comes: the drop (anywhere) ends it too.
    ghostEl = ghost;
    paint(from);
    const end = () => carrying?.();
    document.addEventListener('dragover', follow, true);
    document.addEventListener('drop', end, true);
    document.addEventListener('dragend', end, true);
    carrying = () => { cancelAnimationFrame(frame); ghostEl = null; document.removeEventListener('dragover', follow, true); document.removeEventListener('drop', end, true); document.removeEventListener('dragend', end, true); ghost.remove(); carrying = null; };
}
let carrying = null;
let ghostEl = null;
/** The card being carried takes the colour of the column it is over (the one it came from, while it is over none). */
function paint(key) {
    if (!ghostEl) return;
    const color = cols.value.find((c) => c.key === key)?.color || '#cbd5e1';
    Object.assign(ghostEl.style, { borderLeftColor: color, backgroundColor: `color-mix(in srgb, ${color} 14%, var(--surface, white))`, outline: `2px solid ${color}`, transition: 'background-color .15s, border-color .15s, outline-color .15s' });
}
watch(over, (key) => paint(key ?? dragging.value?.from));
const stopCarrying = () => carrying?.();
onBeforeUnmount(stopCarrying);
function drop(col) {
    over.value = null;
    const d = dragging.value; dragging.value = null;
    if (d) move(d.card, d.from, col.key);
}
const moveOpened = (to) => { const card = openedCard.value; if (card) move(card, stageOf(card.id), to); };
function removed(id) {
    const col = data.value.columns.find((c) => c.cards.some((x) => x.id === id));
    if (col) { col.cards = col.cards.filter((c) => c.id !== id); col.total--; col.sum = null; }
    opened.value = null;
}

const numberFormat = (v) => Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 });
const empty = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length);
const shownFields = (card) => data.value.display.slice(1).filter((f) => !empty(card.values[f.key]));
function cell(card, f) {
    const v = card.values[f.key];
    if (f.type === 'checkbox') return v ? 'Yes' : 'No';
    if (f.type === 'number') return numberFormat(v);
    if (f.type === 'date') return fmtDate(v);
    if (f.type === 'datetime') return fmtDate(v, true);
    return Array.isArray(v) ? v.join(', ') : v;
}
const share = (c) => (total.value ? Math.max(c.total ? 2 : 0, (c.total / total.value) * 100) : 0);
</script>

<template>
    <Head :title="data?.board.name ?? 'Board'" />
    <PageHeader v-if="data" :title="data.board.name" :subtitle="data.board.description || `${data.object.name} — drag a card to another column, or open it for the details`" :crumbs="[{ label: 'Workflow boards', href: '/boards' }, { label: data.board.name }]">
        <button type="button" class="btn-outline" title="Reload the board" aria-label="Reload the board" @click="load"><RefreshCw class="size-4" :class="refreshing && 'animate-spin'" /> Reload</button>
    </PageHeader>

    <template v-if="data">
        <div class="card mb-4 space-y-3 p-4" data-testid="board-summary">
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm">
                <span><span class="text-2xl font-semibold">{{ total }}</span> <span class="text-slate-500">{{ data.object.name.toLowerCase() }} item{{ total === 1 ? '' : 's' }}</span></span>
                <span class="text-slate-500">{{ cols.length }} columns, grouped by <span class="font-medium text-slate-700">{{ data.board.key_field }}</span></span>
                <span v-if="data.narrowed" class="text-brand-700" data-testid="board-narrowed">Showing only what matches your search and filters</span>
            </div>
            <div v-if="total" class="flex h-2 overflow-hidden rounded-full bg-slate-100" role="img" :aria-label="`${total} items across ${cols.length} columns`">
                <div v-for="c in cols" :key="c.key" :style="{ width: `${share(c)}%`, background: c.color || '#cbd5e1' }" :title="`${c.label}: ${c.total}`" />
            </div>
        </div>

        <div class="mb-4 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative"><Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input v-model="q" class="input !w-72 !pl-9" placeholder="Search every card on the board…" aria-label="Search the board" data-testid="board-search" /></div>
                <button type="button" class="btn-outline" :class="activeRules.length && '!border-brand-500 !text-brand-700'" data-testid="board-filters" @click="showFilter = !showFilter"><Filter class="size-4" /> Filters<span v-if="activeRules.length" class="badge ml-1 !bg-brand-50 !text-brand-700">{{ activeRules.length }}</span></button>
                <select v-model="updated" class="input !w-44" aria-label="Changed" data-testid="board-updated"><option value="">Changed any time</option><option value="today">Changed today</option><option value="7d">Changed in 7 days</option><option value="30d">Changed in 30 days</option></select>
                <select v-model="withContacts" class="input !w-44" aria-label="Contacts" data-testid="board-contacts"><option value="">With or without contacts</option><option value="with">With contacts</option><option value="without">Without contacts</option></select>
                <button v-if="narrowedBy" type="button" class="btn-ghost" data-testid="board-clear" @click="clearAll"><X class="size-4" /> Clear</button>
            </div>
            <div v-if="showFilter" class="card space-y-3 p-4">
                <FilterBuilder v-model="filter" :fields="data.fields" />
                <div class="flex justify-end"><button type="button" class="btn-primary" data-testid="board-apply" @click="load">Apply filters</button></div>
            </div>
        </div>

        <div ref="scroller" class="-mx-4 flex items-stretch gap-4 overflow-x-auto overflow-y-hidden px-4 pb-3 lg:-mx-8 lg:px-8" data-testid="board-columns">
            <section v-for="col in cols" :key="col.key" class="flex max-h-full w-80 shrink-0 flex-col rounded-xl border border-slate-200/70 bg-slate-100/70 transition" :class="over === col.key && 'bg-brand-50/60 ring-2 ring-brand-500'"
                @dragover.prevent="over = col.key" @dragleave.self="over = null" @drop.prevent="drop(col)">
                <header class="rounded-t-xl bg-slate-50 px-4 py-3" :style="{ borderTop: `4px solid ${col.color || '#cbd5e1'}` }">
                    <div class="flex items-center justify-between gap-2 text-sm font-semibold"><span class="truncate">{{ col.label }}</span><span class="badge">{{ col.total }}</span></div>
                    <div v-if="data.sum_field && col.sum !== null" class="mt-0.5 text-xs font-normal text-slate-500">{{ data.sum_field.label }}: <span class="font-medium text-slate-700">{{ numberFormat(col.sum) }}</span></div>
                </header>
                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                    <article v-for="card in visible(col)" :key="card.id" draggable="true" tabindex="0" role="button" :aria-label="`Open ${cardTitle(card)}`" data-testid="board-card"
                        class="card cursor-grab space-y-2 border-l-4 p-3 text-sm outline-none transition hover:-translate-y-px hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-500 active:cursor-grabbing"
                        :style="{ borderLeftColor: col.color || '#cbd5e1' }" :class="[dragging?.card.id === card.id && 'opacity-40', landed === card.id && 'card-landed']"
                        @click="opened = card.id" @keydown.enter.self="opened = card.id" @dragstart="start($event, card, col.key)" @dragend="dragging = null; over = null; stopCarrying()">
                        <div class="font-medium leading-snug">{{ cardTitle(card) }}</div>
                        <dl v-if="shownFields(card).length" class="space-y-1">
                            <div v-for="f in shownFields(card)" :key="f.key" class="flex items-baseline justify-between gap-3 text-xs">
                                <dt class="shrink-0 text-slate-400">{{ f.label }}</dt>
                                <dd class="min-w-0 truncate text-right text-slate-700" :class="f.type === 'number' && 'font-semibold tabular-nums'" :title="cell(card, f)">
                                    <span v-if="f.type === 'select'" class="badge">{{ cell(card, f) }}</span>
                                    <template v-else>{{ cell(card, f) }}</template>
                                </dd>
                            </div>
                        </dl>
                        <div class="flex items-center justify-between gap-2 border-t border-slate-100 pt-2">
                            <div v-if="card.contacts.length" class="flex min-w-0 flex-wrap gap-1" @click.stop>
                                <Link v-for="c in card.contacts.slice(0, 2)" :key="c.id" :href="`/contacts/${c.id}`" class="badge max-w-[8rem] hover:bg-brand-50" :title="c.has_tasks ? `${c.name}: has outstanding tasks` : c.name"><span class="truncate">{{ c.name }}</span><ListTodo v-if="c.has_tasks" class="ml-1 size-3 shrink-0 text-amber-600" /></Link>
                                <span v-if="card.contacts.length > 2" class="badge" :title="card.contacts.slice(2).map((c) => c.name).join(', ')">+{{ card.contacts.length - 2 }}</span>
                            </div>
                            <span v-else class="text-xs text-slate-300">No contacts</span>
                            <span class="shrink-0 text-xs text-slate-400" :title="fmtDate(card.updated_at, true)">{{ fmtRelative(card.updated_at) }}</span>
                        </div>
                    </article>
                    <p v-if="!col.cards.length" class="rounded-lg border border-dashed border-slate-300 py-8 text-center text-xs text-slate-400">{{ data.narrowed ? 'Nothing here matches' : 'Drop cards here' }}</p>
                    <p v-if="col.total > col.cards.length" class="py-1 text-center text-xs text-slate-400">+{{ col.total - col.cards.length }} more not shown</p>
                </div>
            </section>
        </div>

        <BoardCardModal v-if="openedCard" :object="data.object" :card-id="openedCard.id" :stage="stageOf(openedCard.id)" :columns="cols" :can="data.can" @close="opened = null" @move="moveOpened" @changed="load" @deleted="removed" />
    </template>

    <div v-else class="flex gap-4 overflow-hidden" aria-busy="true">
        <div v-for="n in 4" :key="n" class="w-80 shrink-0 space-y-2 rounded-xl bg-slate-100/70 p-3">
            <div class="h-5 w-1/2 animate-pulse rounded bg-slate-200" />
            <div v-for="m in 3" :key="m" class="h-20 animate-pulse rounded-lg bg-white" />
        </div>
    </div>
</template>

<style scoped>
/* a dropped card settles into its place: it comes in slightly lifted and tilted, overshoots a little, and a ring in the column's colour fades out */
.card-landed { animation: card-land 0.55s cubic-bezier(0.22, 1.2, 0.36, 1); }
@keyframes card-land {
    0% { transform: translateY(-14px) rotate(4deg) scale(1.04); box-shadow: 0 16px 32px rgba(15, 23, 42, 0.3); }
    60% { transform: translateY(2px) rotate(-0.6deg) scale(0.995); box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12); }
    100% { transform: none; box-shadow: none; }
}
@media (prefers-reduced-motion: reduce) { .card-landed { animation: none; } }
</style>
