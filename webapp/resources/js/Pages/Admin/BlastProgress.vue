<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RefreshCw } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { fmtDate } from '@/lib/ui';

const users = ref([]);
const userId = ref('');
const data = ref(null);
let timer;

async function load() { data.value = await api.get('/messages/blast', { user_id: userId.value }); }
onMounted(async () => { users.value = (await api.get('/messages/blast-users')).data; load(); timer = setInterval(load, 15000); });
onBeforeUnmount(() => clearInterval(timer));
watch(userId, load);
const color = { queued: 'bg-amber-50 text-amber-700', sending: 'bg-blue-50 text-blue-700', failed: 'bg-red-50 text-red-700' };
const sum = (o) => Object.entries(o ?? {});
</script>

<template>
    <AdminPage title="Blast progress" subtitle="Track progress of all user emails and SMS in the queue (last 7 days, refreshes every 15s).">
        <template #actions>
            <select v-model="userId" class="input !w-52"><option value="">All users</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            <button class="btn-outline" @click="load"><RefreshCw class="size-4" /></button>
        </template>
        <div v-if="data" class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="[label, s] in [['Emails', data.summary.emails], ['SMS', data.summary.sms]]" :key="label" class="card p-5">
                    <h2 class="text-sm font-semibold">{{ label }}</h2>
                    <div class="mt-3 flex flex-wrap gap-2"><span v-for="[k, v] in sum(s)" :key="k" class="badge" :class="color[k]">{{ k }}: {{ v }}</span><span v-if="!sum(s).length" class="text-sm text-slate-400">Nothing sent in the last 7 days.</span></div>
                </div>
            </div>
            <section v-for="g in [{ t: 'Emails waiting / failed', rows: data.emails, f: 'subject' }, { t: 'SMS waiting / failed', rows: data.sms, f: 'body' }]" :key="g.t" class="card">
                <h2 class="card-title">{{ g.t }}</h2>
                <div class="table-scroll"><table class="w-full text-sm"><tbody class="divide-y divide-slate-100">
                    <tr v-for="m in g.rows" :key="m.id"><td class="max-w-md truncate px-5 py-2.5">{{ m[g.f] }}</td><td class="px-3 py-2.5 text-slate-500">{{ m.contact ? `${m.contact.first_name ?? ''} ${m.contact.last_name ?? ''}` : '' }}</td>
                        <td class="px-3 py-2.5"><span class="badge" :class="color[m.status]" :title="m.error">{{ m.status }}</span></td><td class="px-5 py-2.5 text-right text-xs text-slate-400">{{ fmtDate(m.scheduled_at || m.created_at, true) }}</td></tr>
                    <tr v-if="!g.rows.length"><td class="px-5 py-8 text-center text-slate-400">Queue is empty.</td></tr>
                </tbody></table></div>
            </section>
        </div>
    </AdminPage>
</template>
