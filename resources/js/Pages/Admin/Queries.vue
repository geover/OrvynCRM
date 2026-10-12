<script setup>
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const items = ref(null);
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
        <div class="card divide-y divide-slate-100">
            <div v-for="q in items" :key="q.id" class="flex items-center justify-between gap-4 px-5 py-4 text-sm">
                <div><Link :href="`/admin/queries/${q.id}`" class="font-medium hover:text-brand-700">{{ q.name }}</Link>
                    <div class="mt-0.5 text-xs text-slate-400">{{ q.scope === 'contact' ? 'Contacts' : 'Data object' }} · by {{ q.creator?.name ?? 'system' }} · updated {{ fmtDate(q.updated_at) }}</div></div>
                <div class="flex gap-2"><Link :href="`/admin/queries/${q.id}`" class="btn-outline">Edit</Link><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(q)"><Trash2 class="size-4" /></button></div>
            </div>
            <p v-if="items && !items.length" class="px-5 py-10 text-center text-sm text-slate-400">No queries yet.</p>
        </div>
    </AdminPage>
</template>
