<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bookmark, Download, Mail, Phone, Plus, Search, Tag, Trash2, UserCog, X } from 'lucide-vue-next';
import DataTable from '@/Components/DataTable.vue';
import Dropdown from '@/Components/Dropdown.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ContactForm from './partials/ContactForm.vue';
import { api } from '@/lib/api';
import { useStored } from '@/lib/stored';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const rows = ref([]);
const next = ref(null);
const loading = ref(true);
const q = ref('');
const tag = ref('');
const owner = ref('');
const sort = ref('-created_at');
const users = ref([]);
const showNew = ref(false);
const selected = ref([]);
const bulk = ref(null);            // {type: 'add_tag' | 'remove_tag' | 'set_owner', value}
const views = useStored('views:contacts', []);
const viewName = ref('');

const columns = [
    { key: 'full_name', label: 'Name', title: true },
    { key: 'email', label: 'Email' },
    { key: 'phone', label: 'Phone', hideBelow: 'lg' },
    { key: 'company_name', label: 'Company', hideBelow: 'lg' },
    { key: 'owner', label: 'Owner', hideBelow: 'xl' },
    { key: 'tags', label: 'Tags', hideBelow: '2xl' },
    { key: 'created_at', label: 'Added', hideBelow: '2xl' },
];
const filtered = computed(() => !!(q.value || tag.value || owner.value));

async function load(reset = true) {
    loading.value = true;
    try {
        const res = await api.get('/contacts', { 'filter[q]': q.value, 'filter[tag]': tag.value, 'filter[owner_id]': owner.value, sort: sort.value, cursor: reset ? undefined : next.value });
        rows.value = reset ? res.data : [...rows.value, ...res.data];
        next.value = res.meta?.next_cursor ?? null;
        if (reset) selected.value = [];
    } finally { loading.value = false; }
}
let t;
watch([q, tag], () => { clearTimeout(t); t = setTimeout(load, 300); });
watch([sort, owner], () => load());
onMounted(async () => { if (new URLSearchParams(location.search).get('new')) showNew.value = true; load(); users.value = (await api.get('/users')).data; });
const created = (c) => { showNew.value = false; router.visit(`/contacts/${c.id}`); };
const clearFilters = () => { q.value = ''; tag.value = ''; owner.value = ''; };
const initials = (c) => ((c.first_name?.[0] ?? '') + (c.last_name?.[0] ?? '')).toUpperCase() || '?';

// ---- saved views (kept in this browser)
function saveView(close) {
    const name = viewName.value.trim();
    if (!name) return;
    views.value = [...views.value.filter((v) => v.name !== name), { name, q: q.value, tag: tag.value, owner: owner.value, sort: sort.value }];
    viewName.value = ''; close();
}
function applyView(v, close) { q.value = v.q; tag.value = v.tag; owner.value = v.owner; sort.value = v.sort; close(); }
const dropView = (v) => { views.value = views.value.filter((x) => x.name !== v.name); };

// ---- bulk actions
async function bulkRun(action, value, done) {
    const ok = await run(() => api.post('/contacts/bulk', { ids: selected.value, action, value: value === '' || value == null ? null : String(value) }), done);
    if (ok) { bulk.value = null; await load(); }
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

    <DataTable v-model:selected="selected" :columns="columns" :rows="rows" :loading="loading" selectable noun="contact" storage-key="contacts" empty-text="No contacts found.">
        <template #toolbar>
            <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-64 !pl-9" placeholder="Search name, email, company, phone…" /></div>
            <input v-model="tag" class="input !w-36" placeholder="Tag" />
            <select v-model="owner" class="input !w-44"><option value="">All owners</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            <select v-model="sort" class="input !w-40"><option value="-created_at">Newest</option><option value="last_name">Last name</option><option value="company_name">Company</option><option value="-updated_at">Recently updated</option></select>
            <button v-if="filtered" type="button" class="btn-ghost" @click="clearFilters"><X class="size-4" /> Clear filters</button>
            <Dropdown align="left" width="w-72">
                <template #trigger="{ toggle }"><button type="button" class="btn-outline" @click="toggle"><Bookmark class="size-4" /> Views<span v-if="views.length" class="badge ml-1">{{ views.length }}</span></button></template>
                <template #default="{ close }">
                    <p class="px-2 pb-1 pt-0.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Saved views</p>
                    <div v-for="v in views" :key="v.name" class="group flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-slate-50">
                        <button type="button" class="min-w-0 flex-1 truncate text-left" @click="applyView(v, close)">{{ v.name }}</button>
                        <button type="button" class="text-slate-300 hover:text-red-600" :title="`Delete ${v.name}`" @click="dropView(v)"><X class="size-4" /></button>
                    </div>
                    <p v-if="!views.length" class="px-2 py-2 text-xs text-slate-400">Set filters, then save them as a view.</p>
                    <form class="mt-1 flex gap-1 border-t border-slate-100 pt-2" @submit.prevent="saveView(close)">
                        <input v-model="viewName" class="input !py-1.5" placeholder="Name this view…" /><button class="btn-outline !px-2.5" :disabled="!viewName.trim()">Save</button>
                    </form>
                </template>
            </Dropdown>
        </template>

        <template #bulk>
            <button class="btn-outline !py-1.5" @click="bulk = { type: 'add_tag', value: '' }"><Tag class="size-4" /> Add tag</button>
            <button class="btn-outline !py-1.5" @click="bulk = { type: 'remove_tag', value: '' }">Remove tag</button>
            <button class="btn-outline !py-1.5" @click="bulk = { type: 'set_owner', value: '' }"><UserCog class="size-4" /> Assign owner</button>
            <button class="btn-outline !py-1.5" @click="exportSelected"><Download class="size-4" /> Export CSV</button>
            <button class="btn-outline !py-1.5" @click="bulkOptOut">Opt out of email</button>
            <button class="btn-outline !py-1.5 !text-red-600 hover:!bg-red-50" @click="bulkDelete"><Trash2 class="size-4" /> Delete</button>
        </template>

        <template #cell-full_name="{ row: c }">
            <div class="flex items-center gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 max-md:hidden" aria-hidden="true">{{ initials(c) }}</span>
                <div class="min-w-0"><Link :href="`/contacts/${c.id}`" class="whitespace-nowrap font-medium text-brand-700 hover:underline">{{ c.full_name }}</Link><div v-if="c.job_title" class="truncate text-xs text-slate-400">{{ c.job_title }}</div></div></div>
        </template>
        <template #cell-email="{ row: c }"><span class="text-slate-600">{{ c.email }}</span></template>
        <template #cell-owner="{ row: c }">{{ c.owner?.name }}</template>
        <template #cell-tags="{ row: c }"><span class="flex flex-wrap gap-1"><span v-for="g in (c.tags ?? []).slice(0, 3)" :key="g" class="badge">{{ g }}</span><span v-if="(c.tags ?? []).length > 3" class="badge">+{{ c.tags.length - 3 }}</span></span></template>
        <template #cell-created_at="{ row: c }"><span class="text-slate-400">{{ fmtDate(c.created_at) }}</span></template>
        <template #actions="{ row: c }">
            <span class="inline-flex gap-0.5 opacity-0 transition focus-within:opacity-100 group-hover:opacity-100 max-md:opacity-100">
                <a v-if="c.email" :href="`mailto:${c.email}`" class="btn-ghost !px-1.5 text-slate-500" :title="`Email ${c.full_name}`" :aria-label="`Email ${c.full_name}`"><Mail class="size-4" /></a>
                <a v-if="c.phone" :href="`tel:${c.phone}`" class="btn-ghost !px-1.5 text-slate-500" :title="`Call ${c.full_name}`" :aria-label="`Call ${c.full_name}`"><Phone class="size-4" /></a>
            </span>
        </template>
        <template #empty>
            <div class="mx-auto max-w-sm"><p class="font-medium text-slate-600">No contacts found.</p>
                <p class="mt-1 text-sm">{{ filtered ? 'Try different filters, or clear them to see everyone.' : 'Add your first contact to get started.' }}</p>
                <button v-if="filtered" class="btn-outline mt-4" @click="clearFilters">Clear filters</button><button v-else class="btn-primary mt-4" @click="showNew = true"><Plus class="size-4" /> New contact</button></div>
        </template>
        <template #footer><div v-if="next" class="border-t border-slate-100 p-3 text-center"><button class="btn-outline" :disabled="loading" @click="load(false)">Load more</button></div></template>
    </DataTable>

    <Modal v-if="showNew" title="New contact" width="max-w-3xl" @close="showNew = false"><ContactForm @saved="created" @cancel="showNew = false" /></Modal>

    <Modal v-if="bulk" :title="{ add_tag: 'Add a tag', remove_tag: 'Remove a tag', set_owner: 'Assign an owner' }[bulk.type]" @close="bulk = null">
        <form id="bulkf" class="space-y-3" @submit.prevent="bulkRun(bulk.type, bulk.value, bulk.type === 'set_owner' ? 'Owner updated' : bulk.type === 'add_tag' ? 'Tag added' : 'Tag removed')">
            <p class="text-sm text-slate-500">Applies to {{ selected.length }} selected contact{{ selected.length === 1 ? '' : 's' }}.</p>
            <div v-if="bulk.type !== 'set_owner'"><label class="label">Tag</label><input v-model="bulk.value" class="input" placeholder="e.g. customer" required /></div>
            <div v-else><label class="label">Owner</label><select v-model="bulk.value" class="input"><option value="">No owner</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
        </form>
        <template #footer><button class="btn-ghost" @click="bulk = null">Cancel</button><button class="btn-primary" form="bulkf">Apply</button></template>
    </Modal>
</template>
