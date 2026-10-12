<script setup>
import { onMounted, ref } from 'vue';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

const users = ref([]);
const saved = ref({});
onMounted(async () => { users.value = (await api.get('/users')).data; });

async function save(u) {
    if (await run(() => api.patch(`/users/${u.id}`, { caller_id: u.caller_id || null }), `Caller ID saved for ${u.name}`)) saved.value[u.id] = true;
}
</script>

<template>
    <AdminPage title="Phone call" subtitle="Configure users with caller IDs.">
        <div class="card max-w-3xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">User</th><th class="px-5 py-3">Caller ID</th><th /></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="u in users" :key="u.id"><td class="px-5 py-3"><div class="font-medium">{{ u.name }}</div><div class="text-xs text-slate-400">{{ u.email }}</div></td>
                        <td class="px-5 py-3"><input v-model="u.caller_id" class="input !w-52" placeholder="+15551234567" pattern="^\+?[0-9 ()\-]{6,20}$" /></td>
                        <td class="px-5 py-3 text-right"><button class="btn-outline" @click="save(u)">Save</button></td></tr>
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400">The caller ID is the number shown to the contact when this user places a call from the CRM.</p>
        </div>
    </AdminPage>
</template>
