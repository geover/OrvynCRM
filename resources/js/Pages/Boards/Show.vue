<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ListTodo } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import { api } from '@/lib/api';
import { toastError } from '@/lib/ui';

const props = defineProps({ boardId: Number });
const data = ref(null);
const dragging = ref(null);      // {card, from}
const over = ref(null);

async function load() { data.value = await api.get(`/boards/${props.boardId}`); }
onMounted(load);

function start(e, card, from) { dragging.value = { card, from }; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(card.id)); }
async function drop(col) {
    over.value = null;
    const d = dragging.value; dragging.value = null;
    if (!d || d.from === col.key) return;
    // optimistic move, roll back on failure
    const snapshot = JSON.stringify(data.value.columns);
    const from = data.value.columns.find((c) => c.key === d.from);
    from.cards = from.cards.filter((c) => c.id !== d.card.id); from.total--;
    col.cards.unshift(d.card); col.total++;
    try { await api.patch(`/boards/${props.boardId}/cards/${d.card.id}`, { to: col.key }); }
    catch (e) { data.value.columns = JSON.parse(snapshot); toastError(e); }
}
const first = (card, display) => card.values[display[0]?.key] ?? `#${card.id}`;
</script>

<template>
    <Head :title="data?.board.name ?? 'Board'" />
    <PageHeader v-if="data" :title="data.board.name" :subtitle="data.board.description || `${data.object.name} — drag cards between columns`" :crumbs="[{ label: 'Workflow boards', href: '/boards' }, { label: data.board.name }]" />
    <div v-if="data" class="-mx-4 flex gap-4 overflow-x-auto px-4 pb-4 lg:-mx-8 lg:px-8">
        <section v-for="col in data.columns" :key="col.key" class="flex w-72 shrink-0 flex-col rounded-xl bg-slate-100/70" :class="over === col.key && 'ring-2 ring-brand-500'"
            @dragover.prevent="over = col.key" @dragleave="over = null" @drop.prevent="drop(col)">
            <header class="flex items-center justify-between rounded-t-xl px-4 py-3 text-sm font-semibold" :style="col.color ? { borderTop: `4px solid ${col.color}` } : { borderTop: '4px solid #cbd5e1' }">
                <span class="truncate">{{ col.label }}</span><span class="badge">{{ col.total }}</span>
            </header>
            <div class="flex-1 space-y-2 overflow-y-auto px-3 pb-3" style="max-height: 70vh">
                <article v-for="card in col.cards" :key="card.id" draggable="true" class="card cursor-grab space-y-1.5 p-3 text-sm active:cursor-grabbing" @dragstart="start($event, card, col.key)" @dragend="dragging = null">
                    <div class="font-medium">{{ first(card, data.display) }}</div>
                    <div v-for="f in data.display.slice(1)" :key="f.key" v-show="card.values[f.key] != null && card.values[f.key] !== ''" class="flex justify-between gap-2 text-xs"><span class="text-slate-400">{{ f.label }}</span><span class="truncate text-slate-600">{{ Array.isArray(card.values[f.key]) ? card.values[f.key].join(', ') : card.values[f.key] }}</span></div>
                    <div v-if="card.contacts.length" class="flex flex-wrap gap-1 pt-1">
                        <Link v-for="c in card.contacts" :key="c.id" :href="`/contacts/${c.id}`" class="badge hover:bg-brand-50" :title="c.has_tasks ? 'Has outstanding tasks' : ''">{{ c.name }}<ListTodo v-if="c.has_tasks" class="ml-1 size-3 text-amber-600" /></Link>
                    </div>
                </article>
                <p v-if="!col.cards.length" class="py-6 text-center text-xs text-slate-400">Drop cards here</p>
                <p v-else-if="col.total > col.cards.length" class="py-1 text-center text-xs text-slate-400">+{{ col.total - col.cards.length }} more</p>
            </div>
        </section>
    </div>
    <p v-else class="text-sm text-slate-400">Loading…</p>
</template>
