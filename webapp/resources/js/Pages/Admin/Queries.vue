<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Database, Plus, Search, Trash2, Users } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import ItemCollection from '@/Components/ItemCollection.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const items = ref(null);
const ofScope = (scope) => (items.value ?? []).filter((q) => (scope === 'contact' ? q.scope === 'contact' : q.scope !== 'contact')).length;
const load = async () => (items.value = (await api.get('/queries')).data);
onMounted(load);
async function remove(q) {
    if (!(await confirmDialog({ title: `Delete “${q.name}”?`, message: 'Dashboard widgets using this query will stop working.', danger: true, confirmLabel: 'Delete' }))) return;
    await run(() => api.delete(`/queries/${q.id}`), 'Query deleted'); load();
}
</script>

<template>
    <AdminPage title="Query builder" subtitle="Build queries to show specific data in dashboard widgets.">
        <template #actions><Link href="/admin/queries/new" class="btn-primary"><Plus class="size-4" /> New query</Link></template>
                <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-3" data-testid="queries-summary" aria-label="Queries in numbers">
            <TicketTile :icon="Search" tone="bg-indigo-100 text-indigo-700" label="Queries" :value="items?.length ?? 0" hint="saved" />
            <TicketTile :icon="Users" tone="bg-sky-100 text-sky-700" label="On contacts" :value="ofScope('contact')" hint="find people" />
            <TicketTile class="col-span-2 lg:col-span-1" :icon="Database" tone="bg-emerald-100 text-emerald-700" label="On data objects" :value="ofScope('object')" hint="find records" />
        </section>
        <ItemCollection :items="items" storage-key="queries" :icon="Search" :search-text="(q) => `${q.name} ${q.creator?.name ?? ''}`" search-label="Find a query…" empty-title="No queries yet" empty-text="A query finds the contacts or records you care about. Use it in dashboard widgets.">
            <template #title="{ item: q }"><Link :href="`/admin/queries/${q.id}`" class="hover:text-brand-700">{{ q.name }}</Link></template>
            <template #badges="{ item: q }"><span class="badge">{{ q.scope === 'contact' ? 'Contacts' : 'Data object' }}</span></template>
            <template #meta="{ item: q }">by {{ q.creator?.name ?? 'system' }} · updated {{ fmtDate(q.updated_at) }}</template>
            <template #actions="{ item: q }"><Link :href="`/admin/queries/${q.id}`" class="btn-outline">Edit</Link><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete" @click="remove(q)"><Trash2 class="size-4" /></button></template>
            <template #empty><Link href="/admin/queries/new" class="btn-primary"><Plus class="size-4" /> New query</Link></template>
        </ItemCollection>
    </AdminPage>
</template>
