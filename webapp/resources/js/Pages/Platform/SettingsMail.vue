<script setup>
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import PasswordField from '@/Components/PasswordField.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { confirmDialog, toast } from '@/lib/ui';

const props = defineProps({ mail: Object, env: Object, my_email: String, sendgrid: Object });
const page = usePage();
const done = () => toast(page.props.flash.success ?? 'Saved');
const failed = (errors, fallback) => toast(Object.values(errors).find((v) => v) ?? fallback, 'error');

const mailForm = useForm({
    enabled: props.mail.enabled, host: props.mail.host, port: props.mail.port ?? '', username: props.mail.username, password: '', clear_password: false,
    scheme: props.mail.scheme, from_address: props.mail.from_address, from_name: props.mail.from_name,
});
const saveMail = () => mailForm.transform((d) => ({ ...d, port: d.port === '' ? null : d.port })).patch('/platform/settings/mail', { preserveScroll: true, onSuccess: () => { mailForm.password = ''; mailForm.clear_password = false; done(); }, onError: (e) => failed(e, 'Could not save the e-mail server') });

// Back to the .env file: clears the saved server, login, password and sender (the SendGrid event settings are not touched).
const hasOverride = computed(() => props.mail.enabled || props.mail.password_set || !!(props.mail.host || props.mail.port || props.mail.username || props.mail.from_address || props.mail.from_name));
const resetMail = async () => {
    if (!(await confirmDialog({ title: 'Reset the e-mail server?', message: 'The saved server, login, password and sender are removed and every e-mail goes through the server in the .env file again.', confirmLabel: 'Reset' }))) return;
    router.post('/platform/settings/mail/reset', {}, { preserveScroll: true, onSuccess: () => { mailForm.defaults({ enabled: false, host: '', port: '', username: '', password: '', clear_password: false, scheme: 'smtp', from_address: '', from_name: '' }); mailForm.reset(); mailForm.clearErrors(); done(); } });
};

const testForm = useForm({ to: props.my_email });
const sendTest = () => testForm.post('/platform/settings/mail/test', { preserveScroll: true, onSuccess: done, onError: (e) => failed(e, 'The test e-mail could not be sent') });
// SendGrid is an SMTP relay: the preset fills in what SendGrid always uses, so only the API key is left to paste.
const useSendGrid = () => {
    Object.assign(mailForm, { enabled: true, host: 'smtp.sendgrid.net', port: 587, username: 'apikey', scheme: 'smtp' });
    toast('SendGrid details filled in. Paste your SendGrid API key as the password, add a verified sender address, and save.');
    document.getElementById('ms-pass')?.focus();
};
const copied = () => navigator.clipboard?.writeText(props.sendgrid.url).then(() => toast('Address copied'), () => toast('Select the address and copy it', 'error'));
const keyForm = useForm({ verification_key: '' });
const saveKey = () => keyForm.patch('/platform/settings/mail/sendgrid', { preserveScroll: true, onSuccess: () => { keyForm.verification_key = ''; done(); }, onError: (e) => failed(e, 'Could not save the verification key') });
const regenerate = async () => {
    if (!(await confirmDialog({ title: 'Create a new event address?', message: 'The current address stops working at once. Paste the new one into SendGrid, or its reports are refused.', confirmLabel: 'Create new address', danger: true }))) return;
    router.post('/platform/settings/mail/sendgrid/rotate', {}, { preserveScroll: true, onSuccess: done });
};
const envSummary = computed(() => `${props.env.host ?? '(none)'}:${props.env.port ?? ''}${props.env.username ? ` as ${props.env.username}` : ''}`);
</script>

<template>
    <Head title="E-mail server" />
    <SettingsShell title="E-mail server" subtitle="The server every e-mail goes through: the one in the .env file, or your own.">

    <div class="space-y-6">
    <form class="card space-y-4 p-5" data-testid="mail-form" @submit.prevent="saveMail">
        <div>
            <h2 class="text-sm font-semibold">E-mail server</h2>
            <p class="mt-1 text-xs text-slate-500">By default the application sends e-mail through the server in its <code>.env</code> file ({{ envSummary }}). Switch this on to use another server for every e-mail the application sends, without touching the file.</p>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
                <label class="flex items-center gap-2 text-sm font-medium"><input v-model="mailForm.enabled" type="checkbox" class="size-4 accent-brand-600" data-testid="mail-enabled" /> Use this e-mail server instead of the one in .env</label>
                <button type="button" class="btn-outline !py-1 text-xs" data-testid="use-sendgrid" @click="useSendGrid">Use SendGrid</button>
            </div>

        <fieldset :disabled="!mailForm.enabled" :class="!mailForm.enabled && 'opacity-50'" class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2"><label class="label" for="ms-host">Server (SMTP host)</label><input id="ms-host" v-model="mailForm.host" class="input" :placeholder="env.host ?? 'smtp.example.com'" data-testid="mail-host" /><p v-if="mailForm.errors.host" class="mt-1 text-xs text-red-600">{{ mailForm.errors.host }}</p></div>
                <div><label class="label" for="ms-port">Port</label><input id="ms-port" v-model="mailForm.port" type="number" min="1" max="65535" class="input" :placeholder="String(env.port ?? 587)" data-testid="mail-port" /><p v-if="mailForm.errors.port" class="mt-1 text-xs text-red-600">{{ mailForm.errors.port }}</p></div>
            </div>
            <div><label class="label" for="ms-scheme">Security</label><select id="ms-scheme" v-model="mailForm.scheme" class="input sm:!w-80"><option value="smtp">Automatic (STARTTLS when the server offers it)</option><option value="smtps">SSL/TLS from the start (usually port 465)</option></select></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ms-user">Username</label><input id="ms-user" v-model="mailForm.username" class="input" autocomplete="off" :placeholder="env.username ?? ''" data-testid="mail-username" /></div>
                <div><label class="label" for="ms-pass">Password</label><PasswordField id="ms-pass" v-model="mailForm.password" autocomplete="new-password" :placeholder="mail.password_set ? 'Saved. Type to replace it.' : ''" />
                    <label v-if="mail.password_set" class="mt-1 flex items-center gap-2 text-xs text-slate-500"><input v-model="mailForm.clear_password" type="checkbox" class="accent-brand-600" /> Remove the saved password</label></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ms-from">Sent from (address)</label><input id="ms-from" v-model="mailForm.from_address" type="email" class="input" :placeholder="env.from_address ?? ''" data-testid="mail-from" /><p v-if="mailForm.errors.from_address" class="mt-1 text-xs text-red-600">{{ mailForm.errors.from_address }}</p></div>
                <div><label class="label" for="ms-name">Sent from (name)</label><input id="ms-name" v-model="mailForm.from_name" class="input" :placeholder="env.from_name ?? ''" /></div>
            </div>
        </fieldset>
        <p class="text-xs text-slate-400">Empty fields fall back to the .env value. The password is stored encrypted and is never shown again.</p>
        <div class="flex flex-wrap items-center gap-2">
            <button class="btn-primary" :disabled="mailForm.processing" data-testid="save-mail">Save e-mail server</button>
            <button type="button" class="btn-ghost" :disabled="!hasOverride" :title="hasOverride ? 'Forget these settings and use the .env file again' : 'Nothing is saved: the .env file is already in use'" data-testid="reset-mail" @click="resetMail">Reset to .env</button>
        </div>
    </form>

    <form class="card p-5" data-testid="mail-test" @submit.prevent="sendTest">
        <h2 class="text-sm font-semibold">Send a test e-mail</h2>
        <p class="mb-3 mt-1 text-xs text-slate-500">Uses what is saved above. If the server refuses it, you see its own message here.</p>
        <div class="flex flex-wrap items-start gap-2">
            <div class="min-w-64 flex-1"><label class="sr-only" for="ms-to">Send the test to</label><input id="ms-to" v-model="testForm.to" type="email" class="input" required data-testid="test-to" /><p v-if="testForm.errors.to || testForm.errors.test" class="mt-1 text-xs text-red-600" data-testid="test-error">{{ testForm.errors.to || testForm.errors.test }}</p></div>
            <button class="btn-outline" :disabled="testForm.processing" data-testid="send-test">{{ testForm.processing ? 'Sending…' : 'Send test' }}</button>
        </div>
    </form>

        <section class="card space-y-4 p-5" aria-labelledby="ms-sendgrid" data-testid="sendgrid-card">
            <div>
                <h2 id="ms-sendgrid" class="text-sm font-semibold">SendGrid event reports</h2>
                <p class="mt-1 text-xs text-slate-500">If the e-mail server above is SendGrid, give SendGrid this address as its Event Webhook (SendGrid: Settings, Mail Settings, Event Webhook). It reports bounces, spam reports, unsubscribes and clicks. Each e-mail carries its company, so the reports reach the right one: companies do not need an address of their own.</p>
            </div>
            <div>
                <label class="label" for="ms-sg-url">Event webhook address</label>
                <div class="flex gap-2"><input id="ms-sg-url" :value="sendgrid.url" readonly class="input font-mono text-xs" data-testid="sendgrid-url" @focus="$event.target.select()" />
                    <button type="button" class="btn-outline shrink-0" data-testid="sendgrid-copy" @click="copied">Copy</button>
                    <button type="button" class="btn-ghost shrink-0 text-red-600" data-testid="sendgrid-rotate" @click="regenerate">Regenerate</button></div>
            </div>
            <form class="space-y-2" data-testid="sendgrid-key-form" @submit.prevent="saveKey">
                <label class="label" for="ms-sg-key">Verification key <span class="normal-case text-slate-400">(optional, recommended)</span></label>
                <div class="flex flex-wrap gap-2"><input id="ms-sg-key" v-model="keyForm.verification_key" class="input min-w-64 flex-1 font-mono text-xs" autocomplete="off" :placeholder="sendgrid.key_set ? 'Saved. Paste a new key to replace it, or save empty to remove it.' : 'Paste it from SendGrid'" data-testid="sendgrid-key" />
                    <button class="btn-outline" :disabled="keyForm.processing" data-testid="sendgrid-key-save">Save key</button></div>
                <p v-if="keyForm.errors.verification_key" class="text-xs text-red-600">{{ keyForm.errors.verification_key }}</p>
                <p class="text-xs text-slate-400">Turn on "Signed Event Webhook" in SendGrid and paste its verification key here: reports that are not signed by SendGrid are then refused. Without it, the secret in the address is the only check. Status: <strong>{{ sendgrid.key_set ? 'signatures are required' : 'secret only' }}</strong><span v-if="sendgrid.key_from_env"> (key from <code>SENDGRID_WEBHOOK_PUBLIC_KEY</code> in .env; a key saved here replaces it)</span>.</p>
            </form>
        </section>
    </div>
</SettingsShell>
</template>
