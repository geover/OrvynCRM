<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bookmark, Building2, Download, LayoutGrid, ListTodo, Mail, MessageSquare, Pencil, Phone, Plus, RotateCcw, Rows3, Search, StickyNote, Tag, Trash2, TrendingDown, TrendingUp, UserCog, X } from 'lucide-vue-next';
import StatTile from '@/Components/StatTile.vue';
import DataTable from '@/Components/DataTable.vue';
import Dropdown from '@/Components/Dropdown.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TablePagination from '@/Components/TablePagination.vue';
import ContactAvatar from '@/Components/ContactAvatar.vue';
import MergeFieldMenu from '@/Components/MergeFieldMenu.vue';
import ContactForm from './partials/ContactForm.vue';
import EmailComposer from './partials/EmailComposer.vue';
import SmsComposer from './partials/SmsComposer.vue';
import { api } from '@/lib/api';
import { insertAtCursor } from '@/lib/mergeFields';
import { useStored } from '@/lib/stored';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const rows = ref([]);
const meta = ref(null);            // the page being shown: {current_page, last_page, per_page, total, from, to}
const page = ref(1);
const perPage = useStored('perpage:contacts', 25);
const loading = ref(true);
const q = ref('');
const tag = ref('');
const owner = ref('');
const sort = ref('-created_at');
// the dashboard's "New (30d)" tile opens the list of the people added in the last N days
const askedDays = Number(new URLSearchParams(location.search).get('added'));
const addedDays = ref(Number.isInteger(askedDays) && askedDays > 0 && askedDays <= 3650 ? askedDays : 0);
const addedSince = () => (addedDays.value ? new Date(Date.now() - addedDays.value * 86400000).toISOString().slice(0, 10) : '');
const users = ref([]);
const showNew = ref(false);
const auth = usePage().props.auth.user;
const canEdit = auth.is_admin || (auth.permissions?.contacts ?? []).includes('edit');
const acting = ref(null);          // {type: 'email' | 'sms' | 'edit', contact}: the row action that is open
const openAction = async (type, c) => {
    if (type !== 'edit') { acting.value = { type, contact: c }; return; }
    await run(async () => { acting.value = { type, contact: (await api.get(`/contacts/${c.id}`)).data }; });   // the form needs every field, the list only has some
};
const edited = (c) => { acting.value = null; const i = rows.value.findIndex((r) => r.id === c.id); if (i >= 0) rows.value[i] = { ...rows.value[i], ...c }; };
const selected = ref([]);
const composing = ref(null);       // 'email' | 'sms': a message to every ticked contact
const smsDraft = ref({ body: '' });
const smsEl = ref(null);
const bulk = ref(null);            // {type: 'add_tag' | 'remove_tag' | 'set_owner', value}
const views = useStored('views:contacts', []);
const viewName = ref('');
const display = useStored('display:contacts', 'table');            // table | cards
const table = ref(null);
const overview = ref(null);                                         // the numbers above the list
async function loadOverview() { try { overview.value = (await api.get('/contacts/overview')).data; } catch { overview.value = null; } }
const delta = computed(() => (overview.value ? overview.value.new_30d - overview.value.previous_30d : 0));

const columns = [
    { key: 'full_name', label: 'Name', title: true, sort: 'full_name' },
    { key: 'email', label: 'Email', sort: 'email' },
    { key: 'phone', label: 'Phone', sort: 'phone', hideBelow: 'lg' },
    { key: 'company_name', label: 'Company', sort: 'company_name', hideBelow: 'lg' },
    { key: 'contact_type', label: 'Type', hideBelow: 'xl' },
    { key: 'open_tasks', label: 'Tasks', hideBelow: 'xl' },
    { key: 'owner', label: 'Owner', sort: 'owner', hideBelow: 'xl' },
    { key: 'tags', label: 'Tags', hideBelow: '2xl' },
    { key: 'created_at', label: 'Added', sort: 'created_at', hideBelow: '2xl' },
    { key: 'updated_at', label: 'Updated', sort: 'updated_at', hideBelow: '2xl', off: true },
];
// the colour of a kind of contact, in the table's badge and along the top of a card
const TYPES = { lead: ['#d9b96a', 'bg-amber-50 text-amber-700'], customer: ['#10b981', 'bg-emerald-50 text-emerald-700'], prospect: ['#0ea5e9', 'bg-sky-50 text-sky-700'], partner: ['#8b5cf6', 'bg-violet-50 text-violet-700'], vendor: ['#f97316', 'bg-orange-50 text-orange-700'] };
const typeOf = (c) => TYPES[(c.contact_type ?? '').trim().toLowerCase()] ?? ['#cbd5e1', 'bg-slate-100 text-slate-600'];
const SORT_CHOICES = ['-created_at', 'last_name', 'company_name', '-updated_at'];
const filtered = computed(() => !!(q.value || tag.value || owner.value || addedDays.value));

async function load() {
    loading.value = true;
    try {
        const res = await api.get('/contacts', { 'filter[q]': q.value, 'filter[tag]': tag.value, 'filter[owner_id]': owner.value, 'filter[created_after]': addedSince(), sort: sort.value, page: page.value, per_page: perPage.value });
        if (res.meta.last_page && page.value > res.meta.last_page) { page.value = res.meta.last_page; return load(); }   // the last page emptied (deleted rows)
        rows.value = res.data;
        meta.value = res.meta;
        selected.value = [];
    } finally { loading.value = false; }
}
const goTo = (n) => { page.value = n; load(); window.scrollTo({ top: 0 }); };
const resize = (n) => { perPage.value = n; goTo(1); };
const fromStart = () => { page.value = 1; load(); };      // a new search, filter or order starts on page 1
let t;
watch([q, tag], () => { clearTimeout(t); t = setTimeout(fromStart, 300); });
watch([sort, owner, addedDays], fromStart);
// a contact opened from a row (the layout's pop-up) may have been changed or deleted: the list follows
onMounted(() => { window.addEventListener('orvyn:contact-changed', load); window.addEventListener('orvyn:contact-changed', loadOverview); window.addEventListener('orvyn:contact-closed', load); });
onBeforeUnmount(() => { window.removeEventListener('orvyn:contact-changed', load); window.removeEventListener('orvyn:contact-changed', loadOverview); window.removeEventListener('orvyn:contact-closed', load); });
onMounted(async () => { if (new URLSearchParams(location.search).get('new')) showNew.value = true; load(); loadOverview(); users.value = (await api.get('/users')).data; });
const created = (c) => { showNew.value = false; router.visit(`/contacts/${c.id}`); };
const showMine = () => { owner.value = auth.id; };
const clearFilters = () => { q.value = ''; tag.value = ''; owner.value = ''; addedDays.value = 0; };

// ---- saved views (kept in this browser)
function saveView(close) {
    const name = viewName.value.trim();
    if (!name) return;
    views.value = [...views.value.filter((v) => v.name !== name), { name, q: q.value, tag: tag.value, owner: owner.value, sort: sort.value, added: addedDays.value, display: display.value, columns: table.value?.getColumns() ?? null }];
    viewName.value = ''; close();
}
function applyView(v, close) {
    q.value = v.q; tag.value = v.tag; owner.value = v.owner; sort.value = v.sort; addedDays.value = v.added ?? 0;
    if (v.display) display.value = v.display;
    if (v.columns && table.value) table.value.setColumns(v.columns);            // a view saved before columns were part of it leaves the columns as they are
    close();
}
/** Back to how the page first looks: no search or filters, newest first, the default columns, as a table. */
function resetView(close) {
    q.value = ''; tag.value = ''; owner.value = ''; addedDays.value = 0; sort.value = '-created_at'; display.value = 'table';
    table.value?.resetColumns();
    close();
    fromStart();
}
const describeView = (v) => [v.columns ? `${v.columns.length} columns` : null, v.display === 'cards' ? 'cards' : null].filter(Boolean).join(' · ');
const dropView = (v) => { views.value = views.value.filter((x) => x.name !== v.name); };

// ---- e-mail or text the ticked contacts (the same forms as for one contact; contacts without an address, or who opted out, are skipped)
const startCompose = (type) => { smsDraft.value = { body: '' }; composing.value = type; };
async function addSmsField(token) {
    const r = insertAtCursor(smsEl.value, smsDraft.value.body, token);
    smsDraft.value.body = r.value;
    await nextTick();
    smsEl.value?.focus();
    smsEl.value?.setSelectionRange(r.caret, r.caret);
}
async function sendToSelected(spec) {
    const n = selected.value.length;
    const email = composing.value === 'email';
    if (!(await confirmDialog({ title: `${email ? 'Email' : 'Text'} ${n} contact${n === 1 ? '' : 's'}?`, message: 'Contacts without ' + (email ? 'an e-mail address' : 'a phone number') + ', who opted out or are suppressed are skipped automatically.', confirmLabel: 'Queue messages' }))) return;
    const body = { scope: 'contact', definition: { contact_filter: { match: 'all', rules: [] } }, ids: selected.value, ...spec };
    if (await run(() => api.post(`/queries/batch/${composing.value}`, body), 'Messages are being queued. Follow the progress under Blast progress.')) composing.value = null;
}

// ---- bulk actions
async function bulkRun(action, value, done) {
    const ok = await run(() => api.post('/contacts/bulk', { ids: selected.value, action, value: value === '' || value == null ? null : String(value) }), done);
    if (ok) { bulk.value = null; await load(); loadOverview(); }
}
async function bulkDelete() {
    const n = selected.value.length;
    if (!(await confirmDialog({ title: `Delete ${n} contact${n === 1 ? '' : 's'}?`, message: 'They are moved out of lists. Notes, tasks and links are kept for audit.', danger: true, confirmLabel: `Delete ${n}` }))) return;
    await bulkRun('delete', null, `${n} deleted`);
}
async function bulkOptOut() {
    const n = selected.value.length;
    if (await confirmDialog({ title: `Opt ${n} contact${n === 1 ? '' : 's'} out of email?`, message: 'They will no longer receive messages from automations or blasts.', confirmLabel: 'Opt out' })) await bulkRun('opt_out', null, `${n} opted out`);
}
const csvCell = (v) => { let s = Array.isArray(v) ? v.join('; ') : (v ?? ''); s = String(s); if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`; return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
function exportSelected() {
    const pick = rows.value.filter((r) => selected.value.includes(r.id));
    const head = ['First name', 'Last name', 'Email', 'Phone', 'Company', 'Job title', 'Owner', 'Tags'];
    const lines = [head.join(','), ...pick.map((c) => [c.first_name, c.last_name, c.email, c.phone, c.company_name, c.job_title, c.owner?.name, c.tags].map(csvCell).join(','))];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
    a.download = `contacts-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click(); URL.revokeObjectURL(a.href);
}
</script>

<template>
    <Head title="Contacts" />
    <PageHeader title="Contacts" subtitle="Everyone you do business with.">
        <button class="btn-primary" @click="showNew = true"><Plus class="size-4" /> New contact</button>
    </PageHeader>

    <section v-if="overview" class="mb-5 space-y-3" data-testid="contacts-summary" aria-label="Contacts in numbers">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <StatTile label="Contacts" :value="overview.total.toLocaleString()" hint="everyone you do business with" />
            <StatTile label="New, last 30 days" :value="overview.new_30d.toLocaleString()" action="Show who" @click="addedDays = 30">
                <div class="flex items-center gap-1 text-xs" :class="delta >= 0 ? 'text-emerald-600' : 'text-red-600'"><component :is="delta >= 0 ? TrendingUp : TrendingDown" class="size-3.5" />{{ delta >= 0 ? '+' : '' }}{{ delta }} on the 30 days before</div>
            </StatTile>
            <StatTile label="My contacts" :value="overview.mine.toLocaleString()" hint="you are the owner" action="Show mine" @click="showMine" />
            <StatTile label="Work waiting" :value="overview.with_open_tasks.toLocaleString()" hint="contacts with an open task" />
            <StatTile label="Opted out" :value="overview.opted_out.toLocaleString()" hint="get no e-mail from you" />
        </div>
        <div v-if="overview.top_tags.length" class="flex flex-wrap items-center gap-2 text-xs"><span class="flex items-center gap-1 text-slate-400"><Tag class="size-3.5" /> Most used tags</span>
            <button v-for="g in overview.top_tags" :key="g.name" type="button" class="badge gap-1 transition hover:bg-brand-50" :class="tag === g.name && '!bg-brand-50 !text-brand-700'" :title="`Show the ${g.count} contacts tagged ${g.name}`" data-testid="top-tag" @click="tag = tag === g.name ? '' : g.name">{{ g.name }} <span class="text-slate-400">{{ g.count }}</span></button></div>
    </section>

    <DataTable ref="table" v-model:selected="selected" v-model:sort="sort" :columns="columns" :rows="rows" :loading="loading" :display="display" selectable noun="contact" storage-key="contacts" empty-text="No contacts found.">
        <template #toolbar>
            <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-64 !pl-9" placeholder="Search name, email, company, phone…" /></div>
            <input v-model="tag" class="input !w-36" placeholder="Tag" />
            <button v-if="addedDays" type="button" class="badge !bg-brand-50 !px-2.5 !py-1.5 !text-brand-700" data-testid="added-chip" title="Show everybody again" @click="addedDays = 0">Added in the last {{ addedDays }} days <X class="ml-1 size-3.5" /></button>
            <select v-model="owner" class="input !w-44"><option value="">All owners</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            <select v-model="sort" class="input !w-40"><option value="-created_at">Newest</option><option value="last_name">Last name</option><option value="company_name">Company</option><option value="-updated_at">Recently updated</option><option v-if="!SORT_CHOICES.includes(sort)" :value="sort">Column order</option></select>
            <button v-if="filtered" type="button" class="btn-ghost" @click="clearFilters"><X class="size-4" /> Clear filters</button>
            <Dropdown align="left" width="w-72">
                <template #trigger="{ toggle }"><button type="button" class="btn-outline" @click="toggle"><Bookmark class="size-4" /> Views<span v-if="views.length" class="badge ml-1">{{ views.length }}</span></button></template>
                <template #default="{ close }">
                    <button type="button" class="mb-1 flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm font-medium text-slate-700 hover:bg-slate-50" data-testid="reset-view" @click="resetView(close)"><RotateCcw class="size-4 text-slate-400" /> Reset view<span class="ml-auto text-xs font-normal text-slate-400">filters, order, columns</span></button>
                    <p class="border-t border-slate-100 px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Saved views</p>
                    <div v-for="v in views" :key="v.name" class="group flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-slate-50">
                        <button type="button" class="min-w-0 flex-1 truncate text-left" @click="applyView(v, close)">{{ v.name }}<span v-if="describeView(v)" class="ml-2 text-xs text-slate-400">{{ describeView(v) }}</span></button>
                        <button type="button" class="text-slate-300 hover:text-red-600" :title="`Delete ${v.name}`" @click="dropView(v)"><X class="size-4" /></button>
                    </div>
                    <p v-if="!views.length" class="px-2 py-2 text-xs text-slate-400">Set filters, then save them as a view.</p>
                    <p class="px-2 pb-1 text-xs text-slate-400">A view remembers the search and filters, the order, the columns you show and table or cards.</p>
                    <form class="mt-1 flex gap-1 border-t border-slate-100 pt-2" @submit.prevent="saveView(close)">
                        <input v-model="viewName" class="input !py-1.5" placeholder="Name this view…" /><button class="btn-outline !px-2.5" :disabled="!viewName.trim()">Save</button>
                    </form>
                </template>
            </Dropdown>
            <div class="inline-flex rounded-lg border border-slate-200 p-0.5" role="group" aria-label="Show contacts as">
                <button type="button" class="rounded-md p-1.5" :class="display === 'table' ? 'bg-brand-50 text-brand-700' : 'text-slate-400 hover:text-slate-700'" title="Table" aria-label="Table" :aria-pressed="display === 'table'" data-testid="view-table" @click="display = 'table'"><Rows3 class="size-4" /></button>
                <button type="button" class="rounded-md p-1.5" :class="display === 'cards' ? 'bg-brand-50 text-brand-700' : 'text-slate-400 hover:text-slate-700'" title="Cards" aria-label="Cards" :aria-pressed="display === 'cards'" data-testid="view-cards" @click="display = 'cards'"><LayoutGrid class="size-4" /></button>
            </div>
        </template>

        <template #bulk>
            <button class="btn-outline !py-1.5" data-testid="bulk-email" @click="startCompose('email')"><Mail class="size-4" /> Email</button>
            <button class="btn-outline !py-1.5" data-testid="bulk-sms" @click="startCompose('sms')"><MessageSquare class="size-4" /> Text</button>
            <button class="btn-outline !py-1.5" @click="bulk = { type: 'add_tag', value: '' }"><Tag class="size-4" /> Add tag</button>
            <button class="btn-outline !py-1.5" @click="bulk = { type: 'remove_tag', value: '' }">Remove tag</button>
            <button class="btn-outline !py-1.5" @click="bulk = { type: 'set_owner', value: '' }"><UserCog class="size-4" /> Assign owner</button>
            <button class="btn-outline !py-1.5" @click="exportSelected"><Download class="size-4" /> Export CSV</button>
            <button class="btn-outline !py-1.5" @click="bulkOptOut">Opt out of email</button>
            <button class="btn-outline !py-1.5 !text-red-600 hover:!bg-red-50" @click="bulkDelete"><Trash2 class="size-4" /> Delete</button>
        </template>

        <template #cell-full_name="{ row: c }">
            <div class="flex items-center gap-3"><ContactAvatar :contact="c" class="max-md:hidden" />
                <div class="min-w-0"><a :href="`/contacts/${c.id}`" class="whitespace-nowrap font-medium text-brand-700 hover:underline" data-testid="contact-open">{{ c.full_name }}</a><div v-if="c.job_title" class="truncate text-xs text-slate-400">{{ c.job_title }}</div></div></div>
        </template>
        <template #cell-email="{ row: c }"><span class="text-slate-600">{{ c.email }}</span><span v-if="c.email_opt_out" class="badge ml-2 !bg-red-50 !text-red-600" title="Gets no e-mail from you">Opted out</span></template>
        <template #cell-contact_type="{ row: c }"><span v-if="c.contact_type" class="badge" :class="typeOf(c)[1]">{{ c.contact_type }}</span></template>
        <template #cell-open_tasks="{ row: c }"><span v-if="c.open_tasks" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700" :title="`${c.open_tasks} open task${c.open_tasks === 1 ? '' : 's'}`"><ListTodo class="size-3.5" />{{ c.open_tasks }}</span><span v-else class="text-slate-300">—</span></template>
        <template #cell-updated_at="{ row: c }"><span class="text-slate-400">{{ fmtDate(c.updated_at) }}</span></template>
        <template #cell-owner="{ row: c }">{{ c.owner?.name }}</template>
        <template #cell-tags="{ row: c }"><span class="flex flex-wrap gap-1"><span v-for="g in (c.tags ?? []).slice(0, 3)" :key="g" class="badge">{{ g }}</span><span v-if="(c.tags ?? []).length > 3" class="badge">+{{ c.tags.length - 3 }}</span></span></template>
        <template #cell-created_at="{ row: c }"><span class="text-slate-400">{{ fmtDate(c.created_at) }}</span></template>
        <template #actions="{ row: c }">
            <span class="inline-flex gap-0.5 opacity-0 transition focus-within:opacity-100 group-hover:opacity-100 max-md:opacity-100">
                <button v-if="c.email" type="button" class="btn-ghost !px-1.5 text-slate-500" :title="`Email ${c.full_name}`" :aria-label="`Email ${c.full_name}`" @click="openAction('email', c)"><Mail class="size-4" /></button>
                <button v-if="c.phone" type="button" class="btn-ghost !px-1.5 text-slate-500" :title="`Text ${c.full_name}`" :aria-label="`Text ${c.full_name}`" @click="openAction('sms', c)"><MessageSquare class="size-4" /></button>
                <a v-if="c.phone" :href="`tel:${c.phone}`" class="btn-ghost !px-1.5 text-slate-500" :title="`Call ${c.full_name}`" :aria-label="`Call ${c.full_name}`"><Phone class="size-4" /></a>
                <button v-if="canEdit" type="button" class="btn-ghost !px-1.5 text-slate-500" :title="`Edit ${c.full_name}`" :aria-label="`Edit ${c.full_name}`" @click="openAction('edit', c)"><Pencil class="size-4" /></button>
            </span>
        </template>
        <template #card="{ row: c, selected: on, toggle }">
            <article class="group relative flex flex-col overflow-hidden rounded-2xl border bg-white transition hover:-translate-y-0.5 hover:shadow-md" :class="on ? 'border-brand-500 ring-1 ring-brand-500' : 'border-slate-200'" data-testid="contact-card">
                <div class="h-1.5" :style="{ background: typeOf(c)[0] }" />
                <div class="flex items-start gap-3 p-4 pb-3">
                    <ContactAvatar :contact="c" size="md" />
                    <div class="min-w-0 flex-1">
                        <a :href="`/contacts/${c.id}`" class="block truncate font-semibold text-brand-700 hover:underline" data-testid="contact-open">{{ c.full_name }}</a>
                        <div v-if="c.job_title || c.company_name" class="mt-0.5 flex items-center gap-1 truncate text-xs text-slate-500"><Building2 v-if="c.company_name" class="size-3 shrink-0 text-slate-400" /><span class="truncate">{{ [c.job_title, c.company_name].filter(Boolean).join(' · ') }}</span></div>
                        <span v-if="c.contact_type" class="badge mt-1.5" :class="typeOf(c)[1]">{{ c.contact_type }}</span>
                    </div>
                    <input type="checkbox" class="mt-1 rounded" :checked="on" :aria-label="`Select ${c.full_name}`" @change="toggle" />
                </div>
                <div class="space-y-1.5 px-4 text-sm">
                    <div v-if="c.email" class="flex items-center gap-2 text-slate-600"><Mail class="size-3.5 shrink-0 text-slate-400" /><span class="truncate">{{ c.email }}</span><span v-if="c.email_opt_out" class="badge shrink-0 !bg-red-50 !text-red-600">Opted out</span></div>
                    <div v-if="c.phone" class="flex items-center gap-2 text-slate-600"><Phone class="size-3.5 shrink-0 text-slate-400" /><span class="truncate">{{ c.phone }}</span></div>
                    <p v-if="!c.email && !c.phone" class="text-xs text-slate-300">No e-mail or phone yet</p>
                </div>
                <div v-if="(c.tags ?? []).length" class="flex flex-wrap gap-1 px-4 pt-3"><span v-for="g in c.tags.slice(0, 4)" :key="g" class="badge">{{ g }}</span><span v-if="c.tags.length > 4" class="badge">+{{ c.tags.length - 4 }}</span></div>
                <div class="mt-3 flex flex-wrap items-center gap-2 px-4 text-xs">
                    <span v-if="c.open_tasks" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700" :title="`${c.open_tasks} open task${c.open_tasks === 1 ? '' : 's'}`"><ListTodo class="size-3.5" />{{ c.open_tasks }} open</span>
                    <span v-if="c.notes_count" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-slate-600" :title="`${c.notes_count} note${c.notes_count === 1 ? '' : 's'}`"><StickyNote class="size-3.5" />{{ c.notes_count }}</span>
                </div>
                <div class="mt-auto flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-400">
                    <span class="flex min-w-0 items-center gap-1.5" :title="c.owner ? `Owner: ${c.owner.name}` : 'No owner'"><span class="grid size-5 shrink-0 place-items-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500">{{ c.owner ? c.owner.name[0] : '–' }}</span><span class="truncate">{{ c.owner?.name ?? 'No owner' }}</span></span>
                    <span class="inline-flex shrink-0 gap-0.5 opacity-60 transition group-hover:opacity-100">
                        <button v-if="c.email" type="button" class="btn-ghost !px-1.5 !py-1" :title="`Email ${c.full_name}`" :aria-label="`Email ${c.full_name}`" @click="openAction('email', c)"><Mail class="size-4" /></button>
                        <button v-if="c.phone" type="button" class="btn-ghost !px-1.5 !py-1" :title="`Text ${c.full_name}`" :aria-label="`Text ${c.full_name}`" @click="openAction('sms', c)"><MessageSquare class="size-4" /></button>
                        <a v-if="c.phone" :href="`tel:${c.phone}`" class="btn-ghost !px-1.5 !py-1" :title="`Call ${c.full_name}`" :aria-label="`Call ${c.full_name}`"><Phone class="size-4" /></a>
                        <button v-if="canEdit" type="button" class="btn-ghost !px-1.5 !py-1" :title="`Edit ${c.full_name}`" :aria-label="`Edit ${c.full_name}`" @click="openAction('edit', c)"><Pencil class="size-4" /></button>
                    </span>
                </div>
            </article>
        </template>
        <template #empty>
            <div class="mx-auto max-w-sm"><p class="font-medium text-slate-600">No contacts found.</p>
                <p class="mt-1 text-sm">{{ filtered ? 'Try different filters, or clear them to see everyone.' : 'Add your first contact to get started.' }}</p>
                <button v-if="filtered" class="btn-outline mt-4" @click="clearFilters">Clear filters</button><button v-else class="btn-primary mt-4" @click="showNew = true"><Plus class="size-4" /> New contact</button></div>
        </template>
        <template #footer><TablePagination :meta="meta" :loading="loading" @page="goTo" @size="resize" /></template>
    </DataTable>

    <!-- a message to the ticked contacts -->
    <EmailComposer v-if="composing === 'email'" :bulk="{ name: 'your selection', title: `Email ${selected.length} contact${selected.length === 1 ? '' : 's'}`, where: 'selected', count: selected.length }" @close="composing = null" @submit="sendToSelected" />
    <Modal v-if="composing === 'sms'" :title="`Text ${selected.length} contact${selected.length === 1 ? '' : 's'}`" @close="composing = null">
        <form id="bulk-sms-form" class="space-y-3" @submit.prevent="sendToSelected({ body: smsDraft.body })">
            <div><div class="mb-1 flex items-center justify-between"><label class="label !mb-0" for="bulk-sms-body">Message</label><MergeFieldMenu @pick="addSmsField" /></div>
                <textarea id="bulk-sms-body" ref="smsEl" v-model="smsDraft.body" rows="5" class="input" required maxlength="1600" />
                <p class="mt-1 text-xs text-slate-400">Merge fields are filled in per contact when it is sent.</p></div>
        </form>
        <template #footer><button class="btn-ghost" @click="composing = null">Cancel</button><button class="btn-primary" form="bulk-sms-form">Send…</button></template>
    </Modal>

    <Modal v-if="showNew" title="New contact" width="max-w-3xl" @close="showNew = false"><ContactForm @saved="created" @cancel="showNew = false" /></Modal>

    <EmailComposer v-if="acting?.type === 'email'" :contact="acting.contact" @close="acting = null" @sent="acting = null" />
    <SmsComposer v-if="acting?.type === 'sms'" :contact="acting.contact" @close="acting = null" @sent="acting = null" />
    <Modal v-if="acting?.type === 'edit'" title="Edit contact" width="max-w-3xl" @close="acting = null"><ContactForm :contact="acting.contact" @saved="edited" @cancel="acting = null" /></Modal>

    <Modal v-if="bulk" :title="{ add_tag: 'Add a tag', remove_tag: 'Remove a tag', set_owner: 'Assign an owner' }[bulk.type]" @close="bulk = null">
        <form id="bulkf" class="space-y-3" @submit.prevent="bulkRun(bulk.type, bulk.value, bulk.type === 'set_owner' ? 'Owner updated' : bulk.type === 'add_tag' ? 'Tag added' : 'Tag removed')">
            <p class="text-sm text-slate-500">Applies to {{ selected.length }} selected contact{{ selected.length === 1 ? '' : 's' }}.</p>
            <div v-if="bulk.type !== 'set_owner'"><label class="label">Tag</label><input v-model="bulk.value" class="input" placeholder="e.g. customer" required /></div>
            <div v-else><label class="label">Owner</label><select v-model="bulk.value" class="input"><option value="">No owner</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
        </form>
        <template #footer><button class="btn-ghost" @click="bulk = null">Cancel</button><button class="btn-primary" form="bulkf">Apply</button></template>
    </Modal>
</template>
