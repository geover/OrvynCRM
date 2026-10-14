<script setup>
import { onMounted, ref } from 'vue';
import AdminPage from '@/Components/AdminPage.vue';
import CallerIdVerifier from '@/Components/CallerIdVerifier.vue';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';

const users = ref([]);
const saved = ref({});
const phone = ref({ default_caller_id: null, can_set: true });          // the platform's say in it
const ids = ref({ source: null, can_manage: false, numbers: [], error: null });       // the numbers Twilio lets this company show
const loadIds = async () => { ids.value = (await api.get('/caller-ids')).data; };
onMounted(async () => { [users.value, phone.value] = await Promise.all([api.get('/users').then((r) => r.data), api.get('/phone').then((r) => r.data)]); await loadIds().catch(() => {}); });

const startVerify = async (number, label) => (await api.post('/caller-ids/verify', { number, label })).data;
const checkVerified = async (number) => (await api.get('/caller-ids/status', { number })).data.verified;
const rename = async (n) => {
    const label = window.prompt('Name for this number', n.label);
    if (label && label !== n.label && await run(() => api.patch(`/caller-ids/${n.sid}`, { label }), 'Name saved')) await loadIds();
};
const remove = async (n) => {
    if (!(await confirmDialog({ title: `Remove ${n.number}?`, message: 'It is removed from your Twilio account. Users who have it as their caller ID keep it until you change it.', confirmLabel: 'Remove', danger: true }))) return;
    if (await run(() => api.delete(`/caller-ids/${n.sid}`), 'Number removed')) await loadIds();
};

async function save(u) {
    if (await run(() => api.patch(`/users/${u.id}`, { caller_id: u.caller_id || null }), `Caller ID saved for ${u.name}`)) saved.value[u.id] = true;
}
</script>

<template>
    <AdminPage title="Phone call" subtitle="Configure users with caller IDs.">
        <p v-if="!phone.can_set" class="mb-3 max-w-3xl rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-testid="phone-locked">Caller IDs are set by the platform administrator for all companies, so they cannot be changed here.</p>
        <div class="card table-scroll max-w-3xl">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">User</th><th class="px-5 py-3">Caller ID</th><th /></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="u in users" :key="u.id"><td class="px-5 py-3"><div class="font-medium">{{ u.name }}</div><div class="text-xs text-slate-400">{{ u.email }}</div></td>
                        <td class="px-5 py-3"><select v-if="ids.numbers.length" v-model="u.caller_id" :disabled="!phone.can_set" class="input !w-56" data-testid="caller-select">
                            <option :value="null">{{ phone.default_caller_id ? `Default: ${phone.default_caller_id}` : 'None' }}</option>
                            <option v-if="u.caller_id && !ids.numbers.some((n) => n.number === u.caller_id)" :value="u.caller_id">{{ u.caller_id }} (not in the list)</option>
                            <option v-for="n in ids.numbers" :key="n.sid" :value="n.number">{{ n.label === n.number ? n.number : `${n.label} (${n.number})` }}</option>
                        </select>
                        <input v-else :disabled="!phone.can_set" v-model="u.caller_id" class="input !w-52" :placeholder="phone.default_caller_id ? `Default: ${phone.default_caller_id}` : '+15551234567'" pattern="^\+?[0-9 ()\-]{6,20}$" /></td>
                        <td class="px-5 py-3 text-right"><button class="btn-outline" :disabled="!phone.can_set" @click="save(u)">Save</button></td></tr>
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400">The caller ID is the number shown to the contact when this user places a call from the CRM.<template v-if="phone.default_caller_id"> Users without one show the platform's default, {{ phone.default_caller_id }}.</template></p>
        </div>

        <section class="card mt-4 max-w-3xl p-5" aria-labelledby="ph-numbers" data-testid="caller-numbers">
            <h2 id="ph-numbers" class="text-sm font-semibold">Numbers you can call from</h2>
            <p v-if="!ids.source" class="mt-2 text-sm text-slate-500" data-testid="caller-none">No Twilio account is set up for this company, so a caller ID is typed in. To pick from verified numbers instead, add your own Twilio account (Admin settings, Twilio), or ask the platform administrator to set one up.</p>
            <p v-else-if="ids.error" class="mt-2 text-sm text-red-600">Twilio could not be reached: {{ ids.error }}</p>
            <template v-else>
                <p class="mb-3 mt-1 text-xs text-slate-500">{{ ids.can_manage ? 'These are the numbers on your Twilio account. A user can only use a number from this list, because Twilio refuses calls from any other.' : 'The platform offers these numbers. Ask the platform administrator if you need another one.' }}</p>
                <p v-if="!ids.numbers.length" class="text-sm text-slate-500">No numbers yet.</p>
                <ul v-else class="divide-y divide-slate-100 text-sm">
                    <li v-for="n in ids.numbers" :key="n.sid" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><span class="font-medium">{{ n.label }}</span> <span class="font-mono text-xs text-slate-500">{{ n.number }}</span> <span class="badge ml-1">{{ n.kind === 'owned' ? 'Owned' : 'Verified' }}</span></span>
                        <span v-if="ids.can_manage && n.kind === 'verified'" class="flex gap-1"><button class="btn-ghost !py-1 text-xs" @click="rename(n)">Rename</button><button class="btn-ghost !py-1 text-xs text-red-600" @click="remove(n)">Remove</button></span>
                    </li>
                </ul>
                <div v-if="ids.can_manage" class="mt-4 border-t border-slate-100 pt-4">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Verify another number</h3>
                    <CallerIdVerifier :start="startVerify" :check="checkVerified" @verified="loadIds" />
                </div>
            </template>
        </section>
    </AdminPage>
</template>
