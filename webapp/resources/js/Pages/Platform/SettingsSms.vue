<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import PasswordField from '@/Components/PasswordField.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';

const props = defineProps({ sms: Object, ready: Boolean });
const page = usePage();
const done = () => toast(page.props.flash.success ?? 'Saved');
const failed = (errors, fallback) => toast(Object.values(errors).find((v) => v) ?? fallback, 'error');

const form = useForm({
    enabled: props.sms.enabled, account_sid: props.sms.account_sid, auth_token: '', clear_token: false,
    from_number: props.sms.from_number, messaging_service_sid: props.sms.messaging_service_sid,
});
const save = () => form.patch('/platform/settings/sms', { preserveScroll: true, onSuccess: () => { form.auth_token = ''; form.clear_token = false; done(); }, onError: (e) => failed(e, 'Could not save the SMS settings') });

const test = useForm({ to: '' });
const sendTest = () => test.post('/platform/settings/sms/test', { preserveScroll: true, onSuccess: done, onError: (e) => failed(e, 'The test message could not be sent') });
</script>

<template>
    <Head title="SMS" />
    <SettingsShell title="SMS" subtitle="Text messages are sent through Twilio. Set the platform's Twilio account here: every company that has not set up its own Twilio account sends through it.">
        <form class="card space-y-4 p-5" data-testid="sms-form" @submit.prevent="save">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Platform Twilio account</h2>
                <span class="badge" :class="ready ? '!bg-emerald-50 !text-emerald-700' : '!bg-amber-50 !text-amber-700'" data-testid="sms-status">{{ ready ? 'Ready: companies can send texts' : 'Not ready' }}</span>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium"><input v-model="form.enabled" type="checkbox" class="size-4 accent-brand-600" data-testid="sms-enabled" /> Let companies without their own Twilio account send text messages through this one</label>

            <fieldset :disabled="!form.enabled" :class="!form.enabled && 'opacity-50'" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label" for="sm-sid">Account SID</label><input id="sm-sid" v-model="form.account_sid" class="input font-mono text-xs" :placeholder="sms.env.account_sid ? `From .env: ${sms.env.account_sid}` : 'AC…'" autocomplete="off" data-testid="sms-sid" /><p v-if="form.errors.account_sid" class="mt-1 text-xs text-red-600">{{ form.errors.account_sid }}</p></div>
                    <div><label class="label" for="sm-token">Auth token</label><PasswordField id="sm-token" v-model="form.auth_token" autocomplete="new-password" :placeholder="sms.token_set ? 'Saved. Type to replace it.' : sms.env.token_set ? 'From .env (TWILIO_AUTH_TOKEN). Type to replace it.' : ''" />
                        <label v-if="sms.token_set" class="mt-1 flex items-center gap-2 text-xs text-slate-500"><input v-model="form.clear_token" type="checkbox" class="accent-brand-600" /> Remove the saved token</label></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label" for="sm-from">Send from (number)</label><input id="sm-from" v-model="form.from_number" :placeholder="sms.env.from_number ? `From .env: ${sms.env.from_number}` : '+15551234567'" class="input" data-testid="sms-from" /><p v-if="form.errors.from_number" class="mt-1 text-xs text-red-600">{{ form.errors.from_number }}</p></div>
                    <div><label class="label" for="sm-service">or Messaging Service SID <span class="normal-case text-slate-400">(optional)</span></label><input id="sm-service" v-model="form.messaging_service_sid" class="input font-mono text-xs" placeholder="MG…" autocomplete="off" /></div>
                </div>
                <p class="text-xs text-slate-500">A number or a messaging service is needed. With a messaging service, Twilio picks the number and the number above is ignored.</p>
            </fieldset>
            <p class="text-xs text-slate-500" data-testid="sms-env-note">Defaults come from the <code>.env</code> file (<code>TWILIO_ACCOUNT_SID</code>, <code>TWILIO_AUTH_TOKEN</code>, <code>TWILIO_FROM_NUMBER</code>, <code>TWILIO_MESSAGING_SERVICE_SID</code>): a field left empty here uses its value from there, and anything you save here replaces it. Not set in .env: {{ ['account_sid', 'token_set', 'from_number', 'messaging_service_sid'].filter((k) => !sms.env[k]).map((k) => ({ account_sid: 'account SID', token_set: 'auth token', from_number: 'number', messaging_service_sid: 'messaging service' })[k]).join(', ') || 'nothing, all four are set' }}.</p>
            <p class="text-xs text-slate-400">The token is stored encrypted and is never shown again. A company that sets up its own Twilio account (its own integration) keeps using that one.</p>
            <button class="btn-primary" :disabled="form.processing" data-testid="save-sms">Save SMS settings</button>
        </form>

        <form class="card p-5" data-testid="sms-test" @submit.prevent="sendTest">
            <h2 class="text-sm font-semibold">Send a test text</h2>
            <p class="mb-3 mt-1 text-xs text-slate-500">Uses the saved account (save first). If Twilio refuses it, you see its own message.</p>
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-64 flex-1"><label class="sr-only" for="sm-to">Send the test to</label><input id="sm-to" v-model="test.to" class="input" placeholder="+15551234567" required data-testid="sms-test-to" /><p v-if="test.errors.to || test.errors.test" class="mt-1 text-xs text-red-600" data-testid="sms-test-error">{{ test.errors.to || test.errors.test }}</p></div>
                <button class="btn-outline" :disabled="test.processing || !ready" data-testid="sms-send-test">{{ test.processing ? 'Sending…' : 'Send test' }}</button>
            </div>
        </form>

        <section class="card p-5" aria-labelledby="sm-how">
            <h2 id="sm-how" class="text-sm font-semibold">How it is wired</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
                <li>A person sends a text from a contact, or an automation sends one. The message is queued and a background worker sends it.</li>
                <li>The worker uses the company's own Twilio account if it has one, otherwise this account.</li>
                <li>Delivery reports come back to each company's own callback address, so the status lands on the right message.</li>
                <li>With nothing set up, the SMS buttons tell the person to ask the platform administrator.</li>
            </ul>
        </section>
    </SettingsShell>
</template>
