<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Link2 } from 'lucide-vue-next';
import PasswordField from '@/Components/PasswordField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { api } from '@/lib/api';
import { timezones } from '@/lib/tz';
import { run } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

const page = usePage();
const me = ref(null);
const profile = ref({ name: '', timezone: 'UTC' });
const pw = ref({ current_password: '', password: '' });
const signature = ref('');
const blast = ref(null);
const pwError = ref('');
const zones = computed(() => timezones(profile.value.timezone));
const guides = computed(() => Object.entries(page.props.auth.user.permissions?.guides ?? {}).filter(([, v]) => v));

onMounted(async () => {
    me.value = (await api.get('/me')).data;
    profile.value = { name: me.value.name, timezone: me.value.timezone };
    signature.value = me.value.signature ?? '';
    blast.value = await api.get('/messages/blast', { user_id: me.value.id });
});

const saveProfile = () => run(() => api.patch('/me', profile.value), 'Profile saved');
const saveSignature = () => run(() => api.patch('/me', { signature: signature.value.trim() || null }), 'Signature saved');
async function changePassword() {
    pwError.value = '';
    try { await api.patch('/me', pw.value); pw.value = { current_password: '', password: '' }; run(async () => {}, 'Password changed'); }
    catch (e) { pwError.value = Object.values(e.errors ?? {})[0]?.[0] ?? e.message; }
}
const total = (o) => Object.values(o ?? {}).reduce((a, b) => a + b, 0);
const label = (k) => k.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
</script>

<template>
    <Head title="User settings" />
    <PageHeader title="User settings" subtitle="Your profile, signature and security." :crumbs="[{ label: 'User settings' }]" />
    <div v-if="me" class="grid max-w-5xl gap-6 lg:grid-cols-2">
        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">Profile</h2>
            <div><label class="label">Name</label><input v-model="profile.name" class="input" /></div>
            <div><label class="label">Email</label><input :value="me.email" class="input" disabled /></div>
            <div><label class="label">Timezone</label><select v-model="profile.timezone" class="input"><option v-for="z in zones" :key="z">{{ z }}</option></select></div>
            <button class="btn-primary" @click="saveProfile">Save profile</button>
        </section>

        <section class="card space-y-3 p-5">
            <h2 class="text-sm font-semibold">Email signature</h2>
            <RichTextEditor v-model="signature" min-height="min-h-32" />
            <p class="text-xs text-slate-400">Appended to every email you send from a contact.</p>
            <button class="btn-primary" @click="saveSignature">Save signature</button>
        </section>

        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">Change password</h2>
            <div><label class="label">Current password</label><input v-model="pw.current_password" type="password" class="input" autocomplete="current-password" /></div>
            <div><label class="label">New password</label><PasswordField v-model="pw.password" check /><p class="mt-1 text-xs text-slate-400">10+ characters with upper and lower case letters and a number. Your API tokens are revoked.</p></div>
            <p v-if="pwError" class="text-sm text-red-600">{{ pwError }}</p>
            <button class="btn-primary" :disabled="!pw.current_password || !pw.password" @click="changePassword">Change password</button>
        </section>

        <section class="card space-y-3 p-5">
            <h2 class="text-sm font-semibold">My blast progress <span class="font-normal text-slate-400">(last 7 days)</span></h2>
            <div v-if="blast" class="grid grid-cols-2 gap-3 text-sm">
                <div v-for="[l, s, q] in [['Emails', blast.summary.emails, blast.emails], ['SMS', blast.summary.sms, blast.sms]]" :key="l" class="rounded-lg border border-slate-200 p-3"><div class="text-xs font-medium uppercase text-slate-500">{{ l }}</div>
                    <div class="mt-1 text-xl font-semibold">{{ total(s) }}</div><div class="text-xs text-slate-400">{{ q.length }} waiting / failed</div></div>
            </div>
        </section>

        <section class="card space-y-2 p-5 lg:col-span-2">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Link2 class="size-4" /> 2-way email sync</h2>
            <p class="text-sm text-slate-600">Connecting your mailbox so sent and received emails appear on contacts is <b>not available yet</b> in this build. Emails you send from OrvynCRM are logged automatically.</p>
        </section>

        <section v-if="guides.length" class="card space-y-3 p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold">User guides</h2>
            <div v-for="[k, v] in guides" :key="k"><div class="label">{{ label(k) }}</div><div class="whitespace-pre-wrap text-sm text-slate-700">{{ v }}</div></div>
        </section>
    </div>
</template>
