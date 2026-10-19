<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminPage from '@/Components/AdminPage.vue';
import PasswordField from '@/Components/PasswordField.vue';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// The company's own Twilio account. Optional: without it the company sends through the platform's account when the platform
// offers one. The token is stored encrypted and is never shown again.
const twilio = ref(null);        // { configured, account_sid, token_hint, from_number, messaging_service_sid, platform_available }
const form = reactive({ account_sid: '', auth_token: '', from_number: '', messaging_service_sid: '' });
const errors = ref({});
const busy = ref(false);

const fill = () => Object.assign(form, { account_sid: twilio.value.account_sid, auth_token: '', from_number: twilio.value.from_number, messaging_service_sid: twilio.value.messaging_service_sid });
const load = async () => { twilio.value = (await api.get('/integrations/twilio')).data; fill(); };
onMounted(load);

async function save() {
    errors.value = {};
    busy.value = true;
    try {
        twilio.value = (await api.put('/integrations/twilio', form)).data;
        fill();
        run(async () => {}, 'Twilio details saved');
    } catch (e) {
        errors.value = e.errors ?? {};
        if (!Object.keys(errors.value).length) run(async () => { throw e; });
    } finally { busy.value = false; }
}
async function remove() {
    if (!(await confirmDialog({ title: 'Remove your Twilio account?', message: twilio.value.platform_available ? 'Your company goes back to sending through the platform\'s account.' : 'Text messages stop until you save new details or the platform offers an account.', confirmLabel: 'Remove', danger: true }))) return;
    await run(async () => { twilio.value = (await api.delete('/integrations/twilio')).data; fill(); }, 'Twilio details removed');
}
const test = () => run(() => api.post('/integrations/twilio/test'), 'Twilio accepted the account');

// The numbers texts go out from: as many as the plan allows. The first is the default; people can pick another when they text.
const nums = ref(null);          // { chosen, max, usable, suggestions: [{number, label}], source, error }
const picked = ref([]);
const typed = ref('');
const numErrors = ref('');
const loadNumbers = async () => { nums.value = (await api.get('/integrations/sms-numbers')).data; picked.value = [...nums.value.chosen]; };
onMounted(loadNumbers);
const addNumber = (n) => { const v = (n ?? typed.value).trim(); if (!v || picked.value.includes(v)) { typed.value = ''; return; } picked.value.push(v); typed.value = ''; };
const makeDefault = (n) => { picked.value = [n, ...picked.value.filter((x) => x !== n)]; };
const dropNumber = (n) => { picked.value = picked.value.filter((x) => x !== n); };
const canAdd = computed(() => nums.value && picked.value.length < nums.value.max);
const free = computed(() => (nums.value?.suggestions ?? []).filter((s) => !picked.value.includes(s.number)));
async function saveNumbers() {
    numErrors.value = '';
    try { nums.value = (await api.put('/integrations/sms-numbers', { numbers: picked.value })).data; picked.value = [...nums.value.chosen]; run(async () => {}, 'Texting numbers saved'); }
    catch (e) { numErrors.value = e.errors?.numbers?.[0] ?? e.message; }
}
</script>

<template>
    <AdminPage title="Twilio" subtitle="Use your own Twilio account for text messages and phone numbers. Optional: without it, your company uses the platform's account when the platform offers one.">
        <div v-if="twilio" class="max-w-3xl space-y-4">
            <section class="card space-y-3 p-5" aria-labelledby="tw-status">
                <h2 id="tw-status" class="text-sm font-semibold">Status</h2>
                <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Your own account</dt><dd class="font-medium" data-testid="twilio-own"><span class="badge" :class="twilio.configured ? '!bg-emerald-50 !text-emerald-700' : ''">{{ twilio.configured ? `Saved (${twilio.account_sid.slice(0, 6)}…)` : 'Not set' }}</span></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Texts are sent through</dt><dd class="font-medium" data-testid="twilio-using">{{ twilio.configured ? 'Your account' : twilio.platform_available ? 'The platform\'s account' : 'Nothing yet' }}</dd></div>
                </dl>
                <p v-if="!twilio.configured" class="text-xs text-slate-500">{{ twilio.platform_available ? 'You do not need to set anything here: the platform offers its own account. Add yours only if you want texts and numbers on your own Twilio bill.' : 'The platform has not set up a Twilio account, so add yours to send text messages.' }}</p>
                <div v-if="twilio.configured" class="flex flex-wrap gap-2 pt-1">
                    <button class="btn-outline" data-testid="twilio-test" @click="test">Test the account</button>
                    <button class="btn-ghost text-red-600" data-testid="twilio-remove" @click="remove">Remove details</button>
                </div>
            </section>

            <form class="card space-y-3 p-5" data-testid="twilio-form" @submit.prevent="save">
                <h2 class="text-sm font-semibold">{{ twilio.configured ? 'Change your account' : 'Your Twilio account' }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label" for="tw-sid">Account SID</label><input id="tw-sid" v-model="form.account_sid" class="input font-mono text-xs" placeholder="AC…" autocomplete="off" required data-testid="twilio-sid" /><p v-if="errors.account_sid" class="mt-1 text-xs text-red-600">{{ errors.account_sid[0] }}</p></div>
                    <div><label class="label" for="tw-token">Auth token</label><PasswordField id="tw-token" v-model="form.auth_token" autocomplete="new-password" :placeholder="twilio.configured ? `Saved (${twilio.token_hint}). Type to replace it.` : ''" /><p v-if="errors.auth_token" class="mt-1 text-xs text-red-600">{{ errors.auth_token[0] }}</p></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label" for="tw-from">Send from (number)</label><input id="tw-from" v-model="form.from_number" class="input" placeholder="+15551234567" data-testid="twilio-from" /><p v-if="errors.from_number" class="mt-1 text-xs text-red-600">{{ errors.from_number[0] }}</p></div>
                    <div><label class="label" for="tw-service">or Messaging Service SID <span class="normal-case text-slate-400">(optional)</span></label><input id="tw-service" v-model="form.messaging_service_sid" class="input font-mono text-xs" placeholder="MG…" autocomplete="off" /></div>
                </div>
                <p class="text-xs text-slate-400">Find the SID and token on the front page of the Twilio console. They are stored encrypted and the token is never shown again. Delivery reports come to the callback address on App details.</p>
                <button class="btn-primary" :disabled="busy" data-testid="twilio-save">{{ twilio.configured ? 'Save changes' : 'Save Twilio details' }}</button>
            </form>

            <section v-if="nums" class="card space-y-3 p-5" aria-labelledby="tw-numbers" data-testid="sms-numbers">
                <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="tw-numbers" class="text-sm font-semibold">Texting numbers</h2><span class="text-xs text-slate-500" data-testid="sms-numbers-count">{{ picked.length }} of {{ nums.max }} allowed by your plan</span></div>
                <p class="text-xs text-slate-500">The numbers your texts are sent from. The first one is the default; whoever writes a text can choose another. With none chosen, texts go out from the number saved with your account, or the platform's.</p>
                <ul v-if="picked.length" class="space-y-1.5">
                    <li v-for="(n, i) in picked" :key="n" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm" :data-number="n">
                        <span class="font-mono">{{ n }}</span><span v-if="i === 0" class="badge !bg-brand-50 !text-brand-700">Default</span>
                        <span class="ml-auto flex gap-1"><button v-if="i > 0" type="button" class="btn-ghost !px-2 !py-1 text-xs" @click="makeDefault(n)">Make default</button><button type="button" class="btn-ghost !px-2 !py-1 text-xs text-red-600" :aria-label="`Remove ${n}`" @click="dropNumber(n)">Remove</button></span>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-400">No number chosen.</p>
                <div v-if="canAdd" class="space-y-2">
                    <div v-if="free.length" class="flex flex-wrap gap-1.5"><button v-for="s in free" :key="s.number" type="button" class="btn-outline !px-2.5 !py-1 text-xs" @click="addNumber(s.number)">+ {{ s.number }}<span v-if="s.label" class="ml-1 text-slate-400">{{ s.label }}</span></button></div>
                    <form class="flex gap-2" @submit.prevent="addNumber()"><input v-model="typed" class="input !w-56" placeholder="+15551234567" aria-label="Add a number" data-testid="sms-number-input" /><button class="btn-outline" data-testid="sms-number-add">Add</button></form>
                </div>
                <p v-else class="text-xs text-amber-700">Your plan allows no more numbers. Choose a bigger plan under Billing &amp; plan.</p>
                <p v-if="nums.error" class="text-xs text-amber-700">Twilio did not list your numbers: {{ nums.error }}</p>
                <p v-if="numErrors" class="text-xs text-red-600" role="alert" data-testid="sms-numbers-error">{{ numErrors }}</p>
                <button class="btn-primary" data-testid="sms-numbers-save" @click="saveNumbers">Save texting numbers</button>
            </section>
        </div>
        <p v-else class="text-sm text-slate-500">Loading…</p>
    </AdminPage>
</template>
