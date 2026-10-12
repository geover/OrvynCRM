<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Eye, EyeOff, GripVertical, RotateCcw } from 'lucide-vue-next';
import { useStored } from '@/lib/stored';
import { toast } from '@/lib/ui';

// A board of cards the viewer can arrange: drag a card by its handle (or focus the handle and use the arrow keys) to a new
// place, hide it, and bring hidden cards back from the "Hidden cards" list. "Reset layout" returns to the default.
// The arrangement is a per-browser preference (like the theme); a card that did not exist when it was saved shows up in its
// default place. `cards` is the default order: [{ id, title, span: 3 | 4 | 6 | 8 | 12, slot?: string }]. Each card is drawn
// by the slot named after its `slot` (or its id).
const props = defineProps({ storageKey: { type: String, required: true }, cards: { type: Array, required: true } });


const saved = useStored(`layout:${props.storageKey}`, { order: [], hidden: [] });
const known = computed(() => props.cards.map((c) => c.id));
const byId = computed(() => Object.fromEntries(props.cards.map((c) => [c.id, c])));
const order = computed(() => {
    const mine = (saved.value.order ?? []).filter((id) => known.value.includes(id));
    return [...mine, ...known.value.filter((id) => !mine.includes(id))];
});
const hiddenIds = computed(() => order.value.filter((id) => (saved.value.hidden ?? []).includes(id)));
const visible = computed(() => order.value.filter((id) => !hiddenIds.value.includes(id)).map((id) => byId.value[id]));
const customized = computed(() => (saved.value.order ?? []).length > 0 || (saved.value.hidden ?? []).length > 0);

const save = (patch) => { saved.value = { order: order.value, hidden: hiddenIds.value, ...patch }; };
function place(id, beforeId) {
    const rest = order.value.filter((x) => x !== id);
    const at = beforeId ? rest.indexOf(beforeId) : -1;
    rest.splice(at < 0 ? rest.length : at, 0, id);
    save({ order: rest });
}
function step(id, dir) {
    const ids = visible.value.map((c) => c.id);
    const target = ids[ids.indexOf(id) + dir];
    if (!target) return;
    const rest = order.value.filter((x) => x !== id);
    const at = rest.indexOf(target);
    rest.splice(dir < 0 ? at : at + 1, 0, id);
    save({ order: rest });
}
const hide = (id) => save({ hidden: [...hiddenIds.value, id] });
const show = (id) => save({ hidden: hiddenIds.value.filter((x) => x !== id) });
const showAll = () => { save({ hidden: [] }); menu.value = false; };
function reset() { saved.value = { order: [], hidden: [] }; menu.value = false; toast('Layout reset'); }

// ---- dragging -------------------------------------------------------------------------------------------------------------------
const armed = ref(null);          // only the handle starts a drag, so charts, inputs and text keep working inside a card
const dragging = ref(null);
const over = ref(null);
function onDragStart(e, id) {
    dragging.value = id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', id);
}
function onDrop(beforeId) {
    if (dragging.value && dragging.value !== beforeId) place(dragging.value, beforeId);
    onDragEnd();
}
function onDragEnd() { dragging.value = null; over.value = null; armed.value = null; }
function onKey(e, id) {
    const dir = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[e.key];
    if (!dir) return;
    e.preventDefault();
    step(id, dir);
}

// ---- the hidden-cards list --------------------------------------------------------------------------------------------------------
const menu = ref(false);
const menuRoot = ref(null);
const outside = (e) => { if (menu.value && !menuRoot.value?.contains(e.target)) menu.value = false; };
const escape = (e) => { if (e.key === 'Escape') menu.value = false; };
onMounted(() => { document.addEventListener('click', outside); document.addEventListener('keydown', escape); });
onBeforeUnmount(() => { document.removeEventListener('click', outside); document.removeEventListener('keydown', escape); });
</script>

<template>
    <div data-testid="card-board">
        <div class="mb-3 flex flex-wrap items-center justify-end gap-2 text-sm">
            <span class="mr-auto hidden text-xs text-slate-400 md:inline">Drag a card by its handle, or focus the handle and use the arrow keys, to move it.</span>
            <div ref="menuRoot" class="relative">
                <button type="button" class="btn-outline !py-1.5" :aria-expanded="menu" aria-haspopup="true" data-testid="hidden-cards-button" @click="menu = !menu"><EyeOff class="size-4" /> Hidden cards ({{ hiddenIds.length }})</button>
                <div v-if="menu" class="absolute right-0 z-30 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" role="dialog" aria-label="Hidden cards" data-testid="hidden-cards">
                    <p v-if="!hiddenIds.length" class="px-2 py-3 text-sm text-slate-500">No hidden cards. Hide a card with the eye button on its corner.</p>
                    <template v-else>
                        <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                            <li v-for="id in hiddenIds" :key="id" class="flex items-center justify-between gap-2 px-2 py-2" :data-hidden-card="id">
                                <span class="min-w-0 truncate text-sm">{{ byId[id].title }}</span>
                                <button type="button" class="btn-ghost !px-2 !py-1 text-xs" :data-show="id" @click="show(id)"><Eye class="size-3.5" /> Show</button>
                            </li>
                        </ul>
                        <button v-if="hiddenIds.length > 1" type="button" class="btn-outline mt-2 w-full !py-1.5 text-xs" data-testid="show-all-cards" @click="showAll">Show all</button>
                    </template>
                </div>
            </div>
            <button type="button" class="btn-outline !py-1.5" :disabled="!customized" data-testid="reset-layout" @click="reset"><RotateCcw class="size-4" /> Reset layout</button>
        </div>

        <div class="board">
            <div v-for="card in visible" :key="card.id" class="board-card group relative [&>.card]:h-full" :data-span="card.span" :class="[dragging === card.id && 'opacity-40', over === card.id && dragging !== card.id && 'rounded-xl ring-2 ring-brand-500 ring-offset-2']"
                :draggable="armed === card.id" :data-card="card.id"
                @dragstart="onDragStart($event, card.id)" @dragover.prevent="over = card.id" @dragleave="over === card.id && (over = null)" @drop.prevent="onDrop(card.id)" @dragend="onDragEnd">
                <div class="absolute -top-3 right-3 z-10 flex items-center gap-0.5 rounded-full border border-slate-200 bg-white px-1 py-0.5 text-slate-500 opacity-0 shadow-sm transition focus-within:opacity-100 group-hover:opacity-100 max-lg:opacity-70">
                    <button type="button" class="cursor-grab rounded-full p-1 hover:bg-slate-100 active:cursor-grabbing" :aria-label="`Move ${card.title}: drag, or use the arrow keys`" data-testid="card-handle" @mousedown="armed = card.id" @mouseup="armed = null" @keydown="onKey($event, card.id)"><GripVertical class="size-4" /></button>
                    <button type="button" class="rounded-full p-1 hover:bg-slate-100" :aria-label="`Hide ${card.title}`" data-testid="card-hide" @click="hide(card.id)"><EyeOff class="size-4" /></button>
                </div>
                <slot :name="card.slot ?? card.id" :card="card" />
            </div>
            <div v-if="dragging" class="board-end grid h-12 place-items-center rounded-xl border-2 border-dashed text-xs text-slate-400" :class="over === '__end' ? 'border-brand-500 text-brand-700' : 'border-slate-300'" @dragover.prevent="over = '__end'" @dragleave="over = null" @drop.prevent="onDrop(null)">Drop here to move it to the end</div>
        </div>
        <p v-if="!visible.length" class="card px-6 py-10 text-center text-sm text-slate-500" data-testid="all-cards-hidden">Every card is hidden. Open "Hidden cards" above to show them again.</p>
    </div>
</template>

<style scoped>
/* Cards sit in rows of up to 12 "columns". Each card asks for its share and then grows into whatever is left of its row, so a row
   never ends with an empty gap (a lone card on the last row simply takes the whole row). */
.board { display: flex; flex-wrap: wrap; gap: 1rem; }
.board-card { flex: 1 1 100%; min-width: 0; }
.board-end { flex: 0 0 100%; }
@media (min-width: 640px) {
    .board-card[data-span='3'] { flex: 3 1 calc(50% - 1rem); }
}
@media (min-width: 1024px) {
    .board-card[data-span='4'] { flex: 4 1 calc(33.333% - 1rem); }
    .board-card[data-span='6'] { flex: 6 1 calc(50% - 1rem); }
    .board-card[data-span='8'] { flex: 8 1 calc(66.667% - 1rem); }
}
@media (min-width: 1280px) {
    .board-card[data-span='3'] { flex: 3 1 calc(25% - 1rem); }
}
</style>
