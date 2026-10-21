<script setup>
import { onMounted, ref } from 'vue';
import { Activity, KeyRound } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import SectionTitle from '@/Components/SectionTitle.vue';
import PasswordField from '@/Components/PasswordField.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtRelative, run } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// The company's Keap details. Saving the key is what lets the platform switch Keap sync on for this company; until then the
// platform's switch stays disabled. The key is stored encrypted and is never shown again.
const keap = ref(null);                       // { configured, key_hint, ready, platform_on, last_synced_at, last_run_at }
const apiKey = ref('');
const errors = ref({});
const busy = ref(false);

const load = async () => { keap.value = (await api.get('/integrations/keap')).data; };
onMounted(load);

async function save() {
    errors.value = {};
    busy.value = true;
    try {
        keap.value = (await api.put('/integrations/keap', { api_key: apiKey.value })).data;
        apiKey.value = '';
        run(async () => {}, 'Keap details saved. Ask your platform administrator to switch Keap sync on.');
    } catch (e) {
        errors.value = e.errors ?? {};
        if (!Object.keys(errors.value).length) run(async () => { throw e; });
    } finally { busy.value = false; }
}
async function remove() {
    if (!(await confirmDialog({ title: 'Remove your Keap details?', message: 'Keap sync stops and stays off until you save new details.', confirmLabel: 'Remove', danger: true }))) return;
    await run(async () => { keap.value = (await api.delete('/integrations/keap')).data; }, 'Keap details removed');
}
const test = () => run(() => api.post('/integrations/keap/test'), 'Keap accepted the key');
const sync = () => run(() => api.post('/integrations/keap/sync'), 'Sync queued');
</script>

<template>
    <AdminPage title="Keap" subtitle="Connect your Keap account so your contacts can be synced. You provide the details; the platform administrator switches the sync on.">
        <div v-if="keap" class="max-w-3xl space-y-4">
            <section class="card space-y-3 p-5" aria-labelledby="kp-status">
                <SectionTitle id="kp-status" :icon="Activity" tone="bg-emerald-100 text-emerald-700" title="Status" hint="Your details, the platform switch and the last sync" />
                <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Your Keap details</dt><dd class="font-medium" data-testid="keap-details"><span class="badge" :class="keap.configured ? '!bg-emerald-50 !text-emerald-700' : '!bg-amber-50 !text-amber-700'">{{ keap.configured ? `Saved (${keap.key_hint})` : 'Not saved' }}</span></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Sync switched on by the platform</dt><dd class="font-medium" data-testid="keap-platform"><span class="badge" :class="keap.platform_on ? '!bg-emerald-50 !text-emerald-700' : ''">{{ keap.platform_on ? 'On' : keap.ready ? 'Off: waiting for the platform' : 'Off' }}</span></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Last synced</dt><dd class="font-medium">{{ keap.last_synced_at ? fmtRelative(keap.last_synced_at) : 'never' }}</dd></div>
                </dl>
                <p v-if="keap.configured && !keap.platform_on" class="text-xs text-slate-500" data-testid="keap-waiting">Your details are saved. Contacts start syncing once your platform administrator switches Keap sync on for your company.</p>
                <div v-if="keap.configured" class="flex flex-wrap gap-2 pt-1">
                    <button class="btn-outline" data-testid="keap-test" @click="test">Test the key</button>
                    <button class="btn-outline" :disabled="!keap.platform_on" :title="keap.platform_on ? '' : 'The platform has not switched Keap sync on yet'" data-testid="keap-sync" @click="sync">Sync now</button>
                    <button class="btn-ghost text-red-600" data-testid="keap-remove" @click="remove">Remove details</button>
                </div>
            </section>

            <form class="card space-y-3 p-5" data-testid="keap-form" @submit.prevent="save">
                <SectionTitle :icon="KeyRound" tone="bg-amber-100 text-amber-700" :title="keap.configured ? 'Replace the key' : 'Your Keap key'" hint="Stored encrypted and never shown again" />
                <div>
                    <label class="label" for="kp-key">Service account key or personal access token</label>
                    <PasswordField id="kp-key" v-model="apiKey" autocomplete="off" :placeholder="keap.configured ? 'Saved. Type to replace it.' : ''" />
                    <p v-if="errors.api_key" class="mt-1 text-xs text-red-600">{{ errors.api_key[0] }}</p>
                    <p class="mt-1 text-xs text-slate-400">Create it in Keap (Settings, then API). It is stored encrypted and never shown again. It lets the platform read your contacts from Keap.</p>
                </div>
                <button class="btn-primary" :disabled="busy || !apiKey" data-testid="keap-save">{{ keap.configured ? 'Replace key' : 'Save Keap details' }}</button>
            </form>
        </div>
        <p v-else class="text-sm text-slate-500">Loading…</p>
    </AdminPage>
</template>
