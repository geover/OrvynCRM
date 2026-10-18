<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, Copy, Database, KeyRound, Lock, Search, Table2 } from 'lucide-vue-next';
import { toast } from '@/lib/ui';

// A read-only look into the central database or one company's database. Everything is on the server and in the address, so a view can be
// shared or reloaded: which place, which table, which tab, the page, the order and the search.
const props = defineProps({
    scope: Object, scopes: Array, db: Object, tables: Array, table: String, structure: Object, data: Object, tab: String, error: String,
});

const base = computed(() => (props.scope.type === 'company' ? `/platform/companies/${props.scope.id}/database` : '/platform/database'));
const filter = ref('');
const shownTables = computed(() => { const t = filter.value.trim().toLowerCase(); return props.tables.filter((x) => !t || x.name.toLowerCase().includes(t)); });
const q = ref(props.data?.q ?? '');
watch(() => props.data?.q, (v) => { q.value = v ?? ''; });

const url = (params = {}) => {
    const merged = { table: props.table, tab: props.tab, page: props.data?.page, per_page: props.data?.per_page, sort: props.data?.sort, dir: props.data?.dir, q: props.data?.q, ...params };
    const query = Object.entries(merged).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
    return query ? `${base.value}?${query}` : base.value;
};
const go = (params) => router.get(url(params), {}, { preserveState: true, preserveScroll: true });
const openTable = (name) => router.get(`${base.value}?table=${encodeURIComponent(name)}`, {}, { preserveState: true });
const switchScope = (e) => { const id = e.target.value; router.get(id ? `/platform/companies/${id}/database` : '/platform/database'); };

let timer;
const search = () => { clearTimeout(timer); timer = setTimeout(() => go({ q: q.value.trim(), page: 1 }), 350); };
const sortBy = (col) => go({ sort: col, dir: props.data.sort === col && props.data.dir === 'asc' ? 'desc' : 'asc', page: 1 });

const columns = computed(() => props.structure?.columns ?? []);
const sensitive = computed(() => new Set(columns.value.filter((c) => c.sensitive).map((c) => c.name)));
const expanded = ref(new Set());
const toggle = (key) => { const s = new Set(expanded.value); s.has(key) ? s.delete(key) : s.add(key); expanded.value = s; };
const isCut = (v) => typeof v === 'string' && v.endsWith('…') && v.length > 400;

const bytes = (n) => (n == null ? '' : n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : n < 1073741824 ? `${(n / 1048576).toFixed(1)} MB` : `${(n / 1073741824).toFixed(2)} GB`);
const num = (n) => Number(n).toLocaleString();
const range = computed(() => { const d = props.data; if (!d || !d.rows.length) return ''; const from = (d.page - 1) * d.per_page + 1; const to = from + d.rows.length - 1; return d.total === null ? `${num(from)}–${num(to)}` : `${num(from)}–${num(to)} of ${num(d.total)}`; });
const copy = async (text) => { await navigator.clipboard?.writeText(text); toast('Copied'); };
</script>

<template>
    <Head title="Database viewer" />
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="page-title">Database viewer</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-500">A read-only look at what is stored: the central database, or the database of one company. Nothing can be changed here. Passwords, tokens and keys are hidden, and each visit is logged.</p>
        </div>
        <div>
            <label class="label" for="db-scope">Look at</label>
            <select id="db-scope" class="input !w-72" :value="scope.id ?? ''" data-testid="db-scope" @change="switchScope">
                <option v-for="s in scopes" :key="s.id ?? 'central'" :value="s.id ?? ''">{{ s.label }}</option>
            </select>
        </div>
    </div>

    <p v-if="error" class="card mb-5 border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" data-testid="db-error">{{ error }}</p>

    <div v-if="db" class="card mb-5 flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 text-sm" data-testid="db-info">
        <span class="flex items-center gap-2"><Database class="size-4 text-brand-600" /><span class="text-slate-500">Database</span>
            <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs" data-testid="db-name">{{ db.name }}</code>
            <button type="button" class="btn-ghost !p-1" title="Copy the name" aria-label="Copy the database name" @click="copy(db.name)"><Copy class="size-3.5" /></button></span>
        <span class="text-slate-500">Engine <strong class="font-medium text-slate-700">{{ db.driver }}</strong></span>
        <span class="text-slate-500">Tables <strong class="font-medium text-slate-700">{{ db.tables }}</strong></span>
        <span v-if="db.size !== null" class="text-slate-500">Size <strong class="font-medium text-slate-700">{{ bytes(db.size) }}</strong></span>
    </div>

    <div v-if="db" class="grid gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
        <!-- the tables -->
        <aside class="card self-start p-3" aria-label="Tables">
            <div class="relative mb-2"><Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" /><input v-model="filter" class="input !py-1.5 !pl-8 text-sm" placeholder="Find a table…" aria-label="Find a table" data-testid="db-table-filter" /></div>
            <ul class="max-h-[70vh] space-y-0.5 overflow-y-auto text-sm" data-testid="db-tables">
                <li v-for="t in shownTables" :key="t.name">
                    <button type="button" class="flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left transition" :class="t.name === table ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100'" :aria-current="t.name === table ? 'true' : undefined" :data-table="t.name" @click="openTable(t.name)">
                        <span class="flex min-w-0 items-center gap-2"><Table2 class="size-3.5 shrink-0" /><span class="truncate font-mono text-xs">{{ t.name }}</span></span>
                        <span class="shrink-0 text-[11px] tabular-nums text-slate-400">{{ t.approx ? '~' : '' }}{{ num(t.rows) }}</span>
                    </button>
                </li>
                <li v-if="!shownTables.length" class="px-2.5 py-2 text-xs text-slate-400">No table matches.</li>
            </ul>
        </aside>

        <!-- the table -->
        <section class="min-w-0" aria-live="polite">
            <div v-if="!table" class="card px-5 py-14 text-center text-sm text-slate-400" data-testid="db-empty">Pick a table on the left to see its rows and how it is built.</div>
            <template v-else>
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-mono text-base font-semibold" data-testid="db-table-name">{{ table }}</h2>
                    <div class="flex rounded-lg bg-slate-100 p-0.5 text-sm" role="tablist">
                        <button v-for="[id, label] in [['data', 'Data'], ['structure', 'Structure']]" :key="id" type="button" role="tab" :aria-selected="tab === id" class="rounded-md px-3 py-1" :class="tab === id ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" :data-tab="id" @click="go({ tab: id, page: 1 })">{{ label }}</button>
                    </div>
                </div>

                <!-- data -->
                <div v-if="tab === 'data' && data" class="card overflow-hidden" data-testid="db-data">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-3">
                        <div class="relative"><Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-64 !py-1.5 !pl-8 text-sm" placeholder="Search the text in this table…" aria-label="Search this table" data-testid="db-search" @input="search" /></div>
                        <div class="flex items-center gap-3 text-sm text-slate-500">
                            <span data-testid="db-range">{{ range || 'No rows' }}</span>
                            <label class="flex items-center gap-1.5"><span class="max-sm:sr-only">Rows</span><select class="input !w-auto !py-1 text-sm" :value="data.per_page" aria-label="Rows per page" @change="go({ per_page: Number($event.target.value), page: 1 })"><option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ n }}</option></select></label>
                            <div class="flex items-center gap-1">
                                <button type="button" class="btn-ghost !px-2 !py-1" :disabled="data.page <= 1" aria-label="Previous page" data-testid="db-prev" @click="go({ page: data.page - 1 })"><ChevronLeft class="size-4" /></button>
                                <span class="min-w-8 text-center tabular-nums">{{ data.page }}</span>
                                <button type="button" class="btn-ghost !px-2 !py-1" :disabled="!data.has_more" aria-label="Next page" data-testid="db-next" @click="go({ page: data.page + 1 })"><ChevronRight class="size-4" /></button>
                            </div>
                        </div>
                    </div>
                    <div class="max-h-[68vh] overflow-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="sticky top-0 z-10 bg-white text-slate-500 shadow-[0_1px_0_0_theme(colors.slate.100)]">
                                    <th v-for="c in columns" :key="c.name" class="whitespace-nowrap px-3 py-2 font-semibold" :aria-sort="data.sort === c.name ? (data.dir === 'asc' ? 'ascending' : 'descending') : 'none'">
                                        <button type="button" class="inline-flex items-center gap-1 font-mono hover:text-slate-800" :class="data.sort === c.name && 'text-brand-700'" :data-sort="c.name" @click="sortBy(c.name)">
                                            {{ c.name }}<Lock v-if="c.sensitive" class="size-3 text-slate-400" title="Hidden" /><ArrowUp v-if="data.sort === c.name && data.dir === 'asc'" class="size-3" /><ArrowDown v-else-if="data.sort === c.name" class="size-3" /><ArrowUpDown v-else class="size-3 opacity-30" />
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-mono">
                                <tr v-for="(row, i) in data.rows" :key="i" class="hover:bg-slate-50" data-testid="db-row">
                                    <td v-for="c in columns" :key="c.name" class="max-w-xs px-3 py-1.5 align-top">
                                        <span v-if="row[c.name] === null" class="italic text-slate-300">NULL</span>
                                        <span v-else-if="sensitive.has(c.name)" class="text-slate-300" title="Hidden">{{ row[c.name] }}</span>
                                        <button v-else type="button" class="block max-w-full text-left" :class="expanded.has(`${i}:${c.name}`) ? 'whitespace-pre-wrap break-all' : 'truncate'" :title="isCut(row[c.name]) ? 'Cut at 400 characters' : String(row[c.name])" @click="toggle(`${i}:${c.name}`)">{{ row[c.name] }}</button>
                                    </td>
                                </tr>
                                <tr v-if="!data.rows.length"><td :colspan="columns.length" class="px-4 py-10 text-center font-sans text-sm text-slate-400">{{ data.q ? 'No row matches that search.' : 'This table is empty.' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- structure -->
                <div v-else-if="tab === 'structure' && structure" class="space-y-5" data-testid="db-structure">
                    <div class="card overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2.5">Column</th><th class="px-4 py-2.5">Type</th><th class="px-4 py-2.5">Null</th><th class="px-4 py-2.5">Default</th><th class="px-4 py-2.5">Notes</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="c in structure.columns" :key="c.name">
                                    <td class="px-4 py-2 font-mono text-xs font-medium">{{ c.name }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ c.type }}</td>
                                    <td class="px-4 py-2 text-xs">{{ c.nullable ? 'yes' : 'no' }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ c.default ?? '—' }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-500"><span v-if="c.auto_increment" class="badge mr-1">auto-increment</span><span v-if="c.sensitive" class="badge !bg-amber-50 !text-amber-700">hidden here</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="structure.indexes.length" class="card p-4">
                        <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold"><KeyRound class="size-4 text-slate-400" /> Indexes</h3>
                        <ul class="space-y-1.5 text-sm">
                            <li v-for="i in structure.indexes" :key="i.name" class="flex flex-wrap items-center gap-2"><code class="font-mono text-xs">{{ i.name }}</code><span class="text-xs text-slate-500">on {{ i.columns.join(', ') }}</span><span v-if="i.primary" class="badge !bg-brand-50 !text-brand-700">primary</span><span v-else-if="i.unique" class="badge">unique</span></li>
                        </ul>
                    </div>
                </div>
            </template>
        </section>
    </div>
</template>
