<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Columns3, ExternalLink, GripVertical, Layers, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { withColors } from '@/lib/boards';
import { confirmDialog, fmtDate, fmtRelative, run } from '@/lib/ui';

const boards = ref(null);
const objects = ref([]);
const modal = ref(false);
const d = ref({});
const errors = ref({});

const PALETTE = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#a855f7', '#14b8a6', '#64748b'];
async function load() {
    boards.value = (await api.get('/boards', { stats: 1 })).data;
    objects.value = (await api.get('/data-objects')).data;
}
onMounted(load);

const q = ref('');
const show = ref('all');                                         // all | active | inactive
const count = (status) => (boards.value ?? []).filter((b) => b.status === status).length;
const items = computed(() => (boards.value ?? []).reduce((n, b) => n + (b.stats?.total ?? 0), 0));
const listed = computed(() => (boards.value ?? []).filter((b) => (show.value === 'all' || b.status === show.value)
    && (!q.value.trim() || `${b.name} ${b.description ?? ''} ${b.data_object?.name ?? ''}`.toLowerCase().includes(q.value.trim().toLowerCase()))));
const colored = (b) => withColors(b.stats?.columns);

const object = computed(() => objects.value.find((o) => o.id === Number(d.value.data_object_id)));
const selects = computed(() => object.value?.fields.filter((f) => f.type === 'select') ?? []);
const choices = computed(() => selects.value.find((f) => f.key === d.value.key_field)?.options?.choices ?? []);
// the columns in the arranged order; a choice that was added to the field since comes last, one that was removed is gone
const orderedChoices = computed(() => { const own = (d.value.column_order ?? []).filter((c) => choices.value.includes(c)); return [...own, ...choices.value.filter((c) => !own.includes(c))]; });
const dragging = ref(null);
function moveColumn(i, by) {
    const l = [...orderedChoices.value];
    const j = i + by;
    if (j < 0 || j >= l.length) return;
    [l[i], l[j]] = [l[j], l[i]];
    d.value.column_order = l;
}
function dropOn(target) {
    if (!dragging.value || dragging.value === target) return;
    const l = orderedChoices.value.filter((c) => c !== dragging.value);
    l.splice(l.indexOf(target), 0, dragging.value);
    d.value.column_order = l;
    dragging.value = null;
}

function open(b = null) {
    errors.value = {};
    const first = objects.value.find((o) => o.fields.some((f) => f.type === 'select'));
    d.value = b ? { ...b, display_fields: [...(b.display_fields ?? [])], colors: { ...(b.colors ?? {}) }, column_order: [...(b.column_order ?? [])] }
        : { name: '', description: '', data_object_id: first?.id ?? '', key_field: first?.fields.find((f) => f.type === 'select')?.key ?? '', relationship_id: '', display_fields: [], colors: {}, column_order: [], status: 'active' };
    modal.value = true;
}
function objectChanged() { d.value.key_field = selects.value[0]?.key ?? ''; d.value.display_fields = []; d.value.colors = {}; d.value.column_order = []; d.value.relationship_id = ''; }
const toggleField = (k) => { const l = d.value.display_fields; d.value.display_fields = l.includes(k) ? l.filter((x) => x !== k) : [...l, k]; };

async function save() {
    errors.value = {};
    const body = { ...d.value, relationship_id: d.value.relationship_id || null, column_order: orderedChoices.value };      // the order as shown in the list
    delete body.id; delete body.data_object; delete body.created_at; delete body.updated_at;
    try { await (d.value.id ? api.put(`/boards/${d.value.id}`, body) : api.post('/boards', body)); modal.value = false; load(); run(async () => {}, 'Board saved'); }
    catch (e) { errors.value = e.errors ?? { name: [e.message] }; }
}
async function remove(b) {
    if (await confirmDialog({ title: `Delete board “${b.name}”?`, message: 'Only the board is deleted, not its records.', danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/boards/${b.id}`), 'Deleted'); load(); }
}
</script>

<template>
    <AdminPage title="Workflow boards" subtitle="Manage boards for a clear overview of state across data objects.">
        <template #actions><button class="btn-primary" @click="open()"><Plus class="size-4" /> Add board</button></template>
        <div v-if="boards" class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="boards-summary">
            <div v-for="[label, value, hint] in [['Boards', boards.length, 'in total'], ['Active', count('active'), 'shown to your people'], ['Inactive', count('inactive'), 'hidden from them'], ['Items', items.toLocaleString(), 'across all boards']]" :key="label" class="card p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ label }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ value }}</div>
                <div class="text-xs text-slate-400">{{ hint }}</div>
            </div>
        </div>

        <div v-if="boards?.length" class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative"><Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input v-model="q" class="input !w-64 !pl-9" placeholder="Find a board…" aria-label="Find a board" /></div>
            <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm" role="group" aria-label="Show">
                <button v-for="[v, l] in [['all', 'All'], ['active', 'Active'], ['inactive', 'Inactive']]" :key="v" type="button" class="rounded-md px-3 py-1 font-medium" :class="show === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" @click="show = v">{{ l }}</button>
            </div>
        </div>

        <div v-if="boards" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            <article v-for="b in listed" :key="b.id" data-testid="board-row" class="card flex flex-col gap-4 p-5 text-sm transition hover:shadow-md" :class="b.status !== 'active' && 'opacity-75'">
                <div class="flex items-start gap-3">
                    <div class="grid size-11 shrink-0 place-items-center rounded-lg" :class="b.status === 'active' ? 'bg-brand-50 text-brand-600' : 'bg-slate-100 text-slate-400'"><Columns3 class="size-5" /></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2"><Link :href="`/boards/${b.id}`" class="truncate font-semibold hover:text-brand-700">{{ b.name }}</Link><span :class="b.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : ''" class="badge shrink-0">{{ b.status }}</span></div>
                        <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ b.description || 'No description' }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                    <span class="flex items-center gap-1"><Layers class="size-3.5 text-slate-400" /> {{ b.data_object?.name }}</span>
                    <span>grouped by {{ b.key_field }}</span>
                    <span>{{ b.display_fields?.length ?? 0 }} card field{{ (b.display_fields?.length ?? 0) === 1 ? '' : 's' }}</span>
                </div>

                <template v-if="b.stats">
                    <div class="flex items-baseline justify-between"><span><span class="text-xl font-semibold tabular-nums">{{ b.stats.total.toLocaleString() }}</span> <span class="text-xs text-slate-400">item{{ b.stats.total === 1 ? '' : 's' }}</span></span><span class="text-xs text-slate-400">{{ colored(b).length }} columns</span></div>
                    <div v-if="b.stats.total" class="flex h-2.5 overflow-hidden rounded-full bg-slate-100" role="img" :aria-label="`${b.name}: ${colored(b).map((c) => `${c.label} ${c.total}`).join(', ')}`">
                        <div v-for="c in colored(b)" :key="c.key" :style="{ width: `${(c.total / b.stats.total) * 100}%`, background: c.color }" :title="`${c.label}: ${c.total}`" />
                    </div>
                    <div v-else class="h-2.5 rounded-full bg-slate-100" />
                    <ul class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                        <li v-for="c in colored(b).slice(0, 8)" :key="c.key" class="flex items-center gap-1.5"><span class="size-2 rounded-full" :style="{ background: c.color }" />{{ c.label }} <span class="tabular-nums text-slate-400">{{ c.total }}</span></li>
                        <li v-if="colored(b).length > 8" class="text-slate-400">+{{ colored(b).length - 8 }} more</li>
                    </ul>
                </template>

                <div class="mt-auto flex items-center justify-between border-t border-slate-100 pt-3">
                    <span class="text-xs text-slate-400" :title="b.stats?.updated_at ? fmtDate(b.stats.updated_at, true) : ''">{{ b.stats?.updated_at ? `Last change ${fmtRelative(b.stats.updated_at)}` : 'No changes yet' }}</span>
                    <div class="flex items-center gap-1">
                        <button class="btn-ghost !px-1.5" title="Edit the board" aria-label="Edit the board" @click="open(b)"><Pencil class="size-4" /></button>
                        <Link :href="`/boards/${b.id}`" class="btn-ghost !px-1.5" title="Open the board" aria-label="Open the board"><ExternalLink class="size-4" /></Link>
                        <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete the board" aria-label="Delete the board" @click="remove(b)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
            </article>
            <p v-if="boards.length && !listed.length" class="col-span-full card px-5 py-10 text-center text-sm text-slate-400">No board matches.</p>
            <p v-if="!boards.length" class="col-span-full card px-5 py-10 text-center text-sm text-slate-400">No boards yet. A board needs a data object with a dropdown field.</p>
        </div>

        <Modal v-if="modal" :title="d.id ? 'Edit board' : 'Add board'" width="max-w-2xl" @close="modal = false">
            <form id="bf" class="space-y-3" @submit.prevent="save">
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Name</label><input v-model="d.name" class="input" required /><p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p></div>
                    <div><label class="label">Status</label><select v-model="d.status" class="input"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
                <div><label class="label">Description</label><input v-model="d.description" class="input" /></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label">Data object type</label><select v-model="d.data_object_id" class="input" @change="objectChanged"><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                    <div><label class="label">Key field (columns)</label><select v-model="d.key_field" class="input" @change="d.column_order = []; d.colors = {}"><option v-for="f in selects" :key="f.key" :value="f.key">{{ f.label }}</option></select>
                        <p v-if="!selects.length" class="mt-1 text-xs text-amber-600">This object has no dropdown fields.</p><p v-if="errors.key_field || errors.data_object_id" class="mt-1 text-xs text-red-600">{{ (errors.key_field || errors.data_object_id)[0] }}</p></div>
                </div>
                <div v-if="object?.relationships?.length"><label class="label">Show contacts linked as</label><select v-model="d.relationship_id" class="input"><option value="">Any relationship</option><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                <div><label class="label">Fields shown on cards</label><div class="flex flex-wrap gap-x-4 gap-y-1 text-sm"><label v-for="f in object?.fields ?? []" :key="f.key" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="d.display_fields.includes(f.key)" @change="toggleField(f.key)" /> {{ f.label }}</label></div></div>
                <div v-if="orderedChoices.length" data-testid="board-columns">
                    <label class="label">Columns <span class="normal-case text-slate-400">(drag, or use the arrows, to arrange how they show on the board; pick a colour for each)</span></label>
                    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200" aria-label="Columns of the board, in order">
                        <li v-for="(c, i) in orderedChoices" :key="c" class="flex items-center gap-2 bg-white px-2 py-1.5 text-sm" :class="dragging === c && 'opacity-40'" draggable="true" :data-column="c"
                            @dragstart="dragging = c" @dragend="dragging = null" @dragover.prevent @drop.prevent="dropOn(c)">
                            <GripVertical class="size-4 shrink-0 cursor-grab text-slate-300" aria-hidden="true" />
                            <span class="w-6 shrink-0 text-right text-xs tabular-nums text-slate-400">{{ i + 1 }}</span>
                            <span class="min-w-0 flex-1 truncate font-medium">{{ c }}</span>
                            <span class="hidden items-center gap-1 sm:flex"><button v-for="col in PALETTE" :key="col" type="button" :style="{ background: col }" class="size-4 rounded-full ring-offset-1 hover:ring-2" :class="d.colors[c] === col && 'ring-2 ring-slate-500'" :aria-label="`Colour ${col} for ${c}`" @click="d.colors[c] = col" /></span>
                            <button type="button" class="btn-ghost !px-1.5 !py-1" :disabled="i === 0" :aria-label="`Move ${c} up`" data-testid="column-up" @click="moveColumn(i, -1)"><ArrowUp class="size-4" /></button>
                            <button type="button" class="btn-ghost !px-1.5 !py-1" :disabled="i === orderedChoices.length - 1" :aria-label="`Move ${c} down`" data-testid="column-down" @click="moveColumn(i, 1)"><ArrowDown class="size-4" /></button>
                        </li>
                    </ul>
                    <p class="mt-1 text-xs text-slate-400">Cards with no value in this field are shown in a “No value” column after the last one, when there are any.</p>
                </div>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = false">Cancel</button><button class="btn-primary" form="bf">Save board</button></template>
        </Modal>
    </AdminPage>
</template>
