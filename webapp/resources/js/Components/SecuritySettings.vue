<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Download, Fingerprint, Link2, ShieldCheck, ShieldOff, Trash2 } from 'lucide-vue-next';
import QRCode from 'qrcode';
import PasswordField from '@/Components/PasswordField.vue';
import { addPasskey, passkeysSupported } from '@/lib/webauthn';
import { toast } from '@/lib/ui';

// "My sign-in security": two-step verification with an authenticator app. Used in the company (as a page of its own) and in the platform area.
const props = defineProps({ security: { type: Object, required: true } });
const page = usePage();
const qr = ref('');
const code = useForm({ code: '' });
const sensitive = useForm({ password: '' });
const action = ref(null);             // 'recovery' | 'disable': the one that is asking for the password
const codes = computed(() => props.security.recovery_codes ?? []);

watch(() => props.security.pending?.uri, async (uri) => { qr.value = uri ? await QRCode.toDataURL(uri, { width: 192, margin: 1 }) : ''; }, { immediate: true });
watch(() => props.security.recovery_codes, async (c) => { if (c?.length) { await nextTick(); document.getElementById('recovery-codes')?.scrollIntoView({ block: 'center' }); } });

const post = (url, data = {}, opts = {}) => router.post(url, data, { preserveScroll: true, ...opts });
const start = () => post('/account/security/2fa/start');
const cancel = () => { code.reset(); post('/account/security/2fa/cancel'); };
const confirm = () => code.post('/account/security/2fa/confirm', { preserveScroll: true, onSuccess: () => code.reset() });
const submitSensitive = () => sensitive.post(action.value === 'disable' ? '/account/security/2fa/disable' : '/account/security/2fa/recovery-codes', {
    preserveScroll: true,
    onSuccess: () => { sensitive.reset(); action.value = null; },
});
const copy = async () => { await navigator.clipboard?.writeText(codes.value.join('\n')); toast('Recovery codes copied'); };
const download = () => {
    const url = URL.createObjectURL(new Blob([`Recovery codes for ${page.props.auth?.user?.email ?? 'your account'}\nEach code works once.\n\n${codes.value.join('\n')}\n`], { type: 'text/plain' }));
    Object.assign(document.createElement('a'), { href: url, download: 'recovery-codes.txt' }).click();
    URL.revokeObjectURL(url);
};

// ---- passkeys
const supported = passkeysSupported();
const passkeyName = ref('');
const adding = ref(false);
const removing = ref(null);            // the passkey asking for the password
const removePassword = ref('');
const removeError = ref('');
const reload = () => router.reload({ only: ['security'], preserveScroll: true });
async function newPasskey() {
    adding.value = true;
    try { await addPasskey(passkeyName.value.trim() || 'This device'); passkeyName.value = ''; toast('Passkey added'); reload(); }
    catch (e) { toast(e.message, 'error'); }
    finally { adding.value = false; }
}
const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
async function removePasskey() {
    removeError.value = '';
    try {
        const res = await fetch(`/account/security/passkeys/${removing.value.id}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ password: removePassword.value }) });
        if (!res.ok) { removeError.value = (await res.json().catch(() => ({}))).message ?? 'Could not remove it'; return; }
        removing.value = null; removePassword.value = ''; toast('Passkey removed'); reload();
    } catch (e) { removeError.value = e.message; }
}
const when = (d) => (d ? new Date(d).toLocaleDateString(undefined, { dateStyle: 'medium' }) : 'never');

// ---- connected accounts (Google, Microsoft, GitHub, single sign-on)
const here = typeof window !== 'undefined' && window.location.pathname.startsWith('/platform') ? 'platform' : 'company';
const disconnecting = ref(null);
const disconnectForm = useForm({ password: '' });
const disconnect = () => disconnectForm.post(`/account/security/social/${disconnecting.value}/disconnect`, { preserveScroll: true, onSuccess: () => { disconnecting.value = null; disconnectForm.reset(); } });

const since = computed(() => (props.security.since ? new Date(props.security.since).toLocaleDateString(undefined, { dateStyle: 'medium' }) : ''));
</script>

<template>
    <div class="space-y-6">
    <section class="card space-y-4 p-5" aria-labelledby="tf-title" data-testid="two-factor-settings">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="security.enabled ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'"><component :is="security.enabled ? ShieldCheck : ShieldOff" class="size-5" /></span>
                <div>
                    <h2 id="tf-title" class="text-sm font-semibold">Two-step verification</h2>
                    <p class="mt-0.5 text-sm text-slate-500">After your password, sign-in asks for a 6-digit code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, 1Password, Authy…). It protects your account even if your password leaks.</p>
                </div>
            </div>
            <span class="badge" :class="security.enabled ? '!bg-emerald-50 !text-emerald-700' : security.required ? '!bg-amber-50 !text-amber-700' : ''" data-testid="two-factor-status">{{ security.enabled ? 'On' : security.required ? 'Required' : 'Off' }}</span>
        </div>

        <p v-if="security.required && !security.enabled" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">Your administrator requires two-step verification for your account. Set it up below to carry on.</p>

        <!-- recovery codes: only ever shown straight after they are made -->
        <div v-if="codes.length" id="recovery-codes" class="rounded-xl border border-amber-200 bg-amber-50/60 p-4" data-testid="recovery-codes">
            <h3 class="text-sm font-semibold text-amber-900">Save your recovery codes</h3>
            <p class="mt-1 text-sm text-amber-900/80">If you lose your phone, each of these signs you in once. They are shown <strong>only now</strong>: keep them somewhere safe, such as a password manager.</p>
            <ul class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm sm:grid-cols-3"><li v-for="c in codes" :key="c" data-testid="recovery-code">{{ c }}</li></ul>
            <div class="mt-3 flex flex-wrap gap-2"><button type="button" class="btn-outline !py-1.5" @click="copy"><Copy class="size-4" /> Copy</button><button type="button" class="btn-outline !py-1.5" @click="download"><Download class="size-4" /> Download</button></div>
        </div>

        <!-- off: set it up -->
        <template v-if="!security.enabled">
            <button v-if="!security.pending" type="button" class="btn-primary" data-testid="two-factor-start" @click="start">Set up two-step verification</button>
            <div v-else class="grid gap-5 sm:grid-cols-[auto_1fr]" data-testid="two-factor-setup">
                <img v-if="qr" :src="qr" alt="QR code to scan with your authenticator app" class="size-48 rounded-lg border border-slate-200 bg-white p-1" />
                <div class="space-y-3 text-sm">
                    <ol class="list-decimal space-y-1 pl-5 text-slate-600">
                        <li>Open your authenticator app and scan the QR code.</li>
                        <li>Or choose "enter a setup key" and type this key: <code class="select-all break-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs" data-testid="two-factor-secret">{{ security.pending.secret }}</code></li>
                        <li>Type the 6-digit code the app shows.</li>
                    </ol>
                    <form class="flex flex-wrap items-start gap-2" @submit.prevent="confirm">
                        <div><input v-model="code.code" class="input w-36 text-center font-mono text-lg tracking-widest" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="123456" aria-label="6-digit code" data-testid="two-factor-confirm-code" /><p v-if="code.errors.code" class="mt-1 text-xs text-red-600" role="alert">{{ code.errors.code }}</p></div>
                        <button class="btn-primary" :disabled="code.processing || code.code.length < 6" data-testid="two-factor-confirm">Turn on</button>
                        <button type="button" class="btn-ghost" @click="cancel">Cancel</button>
                    </form>
                </div>
            </div>
        </template>

        <!-- on: manage -->
        <template v-else>
            <p class="text-sm text-slate-500">On since {{ since }}. You have <strong class="text-slate-700" data-testid="recovery-left">{{ security.recovery_left }}</strong> recovery {{ security.recovery_left === 1 ? 'code' : 'codes' }} left.</p>
            <div v-if="!action" class="flex flex-wrap gap-2">
                <button type="button" class="btn-outline" data-testid="two-factor-new-codes" @click="action = 'recovery'">Make new recovery codes</button>
                <button type="button" class="btn-outline !text-red-600 hover:!bg-red-50" :disabled="security.required" :title="security.required ? 'Your administrator requires it' : undefined" data-testid="two-factor-disable" @click="action = 'disable'">Turn off</button>
            </div>
            <form v-else class="max-w-sm space-y-2" @submit.prevent="submitSensitive">
                <label class="label" for="tf-password">{{ action === 'disable' ? 'Enter your password to turn it off' : 'Enter your password to make new codes (the old ones stop working)' }}</label>
                <PasswordField id="tf-password" v-model="sensitive.password" autocomplete="current-password" data-testid="two-factor-password" />
                <p v-if="sensitive.errors.password" class="text-xs text-red-600" role="alert">{{ sensitive.errors.password }}</p>
                <div class="flex gap-2"><button class="btn-primary" :class="action === 'disable' && '!bg-red-600 hover:!bg-red-700'" :disabled="sensitive.processing || !sensitive.password" data-testid="two-factor-password-submit">{{ action === 'disable' ? 'Turn off' : 'Make new codes' }}</button><button type="button" class="btn-ghost" @click="action = null; sensitive.reset()">Cancel</button></div>
            </form>
        </template>
    </section>

    <!-- passkeys: a fingerprint, face, device PIN or security key instead of a password -->
    <section v-if="security.passkeys_enabled" class="card space-y-4 p-5" aria-labelledby="pk-title" data-testid="passkeys">
        <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600"><Fingerprint class="size-5" /></span>
            <div><h2 id="pk-title" class="text-sm font-semibold">Passkeys</h2><p class="mt-0.5 text-sm text-slate-500">Sign in with your fingerprint, face or device PIN: no password to type or to steal. A passkey is also your second step, so no code is asked for. Add one on each device you use.</p></div>
        </div>
        <ul v-if="security.passkeys.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200">
            <li v-for="k in security.passkeys" :key="k.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm" data-testid="passkey-row">
                <div><span class="font-medium">{{ k.name }}</span><span v-if="k.backed_up" class="badge ml-2" title="Kept safe by your password manager or cloud account">synced</span><div class="text-xs text-slate-500">Added {{ when(k.created_at) }} · last used {{ when(k.last_used_at) }}</div></div>
                <button type="button" class="btn-ghost !px-2 text-red-600" :aria-label="`Remove ${k.name}`" data-testid="passkey-remove" @click="removing = k; removePassword = ''; removeError = ''"><Trash2 class="size-4" /></button>
            </li>
        </ul>
        <form v-if="removing" class="max-w-sm space-y-2" @submit.prevent="removePasskey">
            <label class="label" for="pk-password">Enter your password to remove “{{ removing.name }}”</label>
            <input id="pk-password" v-model="removePassword" type="password" class="input" autocomplete="current-password" data-testid="passkey-password" />
            <p v-if="removeError" class="text-xs text-red-600" role="alert">{{ removeError }}</p>
            <div class="flex gap-2"><button class="btn-primary !bg-red-600 hover:!bg-red-700" :disabled="!removePassword" data-testid="passkey-remove-confirm">Remove</button><button type="button" class="btn-ghost" @click="removing = null">Cancel</button></div>
        </form>
        <p v-if="!supported" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">This browser cannot make a passkey. Try a current version of Chrome, Edge, Safari or Firefox.</p>
        <form v-else class="flex flex-wrap items-end gap-2" @submit.prevent="newPasskey">
            <div><label class="label" for="pk-name">Name this device</label><input id="pk-name" v-model="passkeyName" class="input w-56" maxlength="80" placeholder="e.g. Work laptop" data-testid="passkey-name" /></div>
            <button class="btn-primary" :disabled="adding" data-testid="passkey-add"><Fingerprint class="size-4" /> {{ adding ? 'Waiting for your device…' : 'Add a passkey' }}</button>
        </form>
    </section>

    <!-- accounts at identity providers -->
    <section v-if="security.social.length" class="card space-y-4 p-5" aria-labelledby="sso-title" data-testid="connected-accounts">
        <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600"><Link2 class="size-5" /></span>
            <div><h2 id="sso-title" class="text-sm font-semibold">Connected accounts</h2><p class="mt-0.5 text-sm text-slate-500">Connect an account you already have to sign in with one click. It is matched to this account, so you keep your companies and your data.</p></div>
        </div>
        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
            <li v-for="p in security.social" :key="p.key" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm" :data-provider="p.key">
                <div><span class="font-medium">{{ p.name }}</span><span v-if="p.connected" class="badge ml-2 !bg-emerald-50 !text-emerald-700">Connected</span><div v-if="p.connected && p.email" class="text-xs text-slate-500">{{ p.email }}</div></div>
                <button v-if="p.connected" type="button" class="btn-outline !py-1.5" :data-testid="`disconnect-${p.key}`" @click="disconnecting = p.key; disconnectForm.reset()">Disconnect</button>
                <a v-else :href="`/account/security/social/${p.key}/link?return=${here}`" class="btn-outline !py-1.5" :data-testid="`connect-${p.key}`">Connect</a>
            </li>
        </ul>
        <form v-if="disconnecting" class="max-w-sm space-y-2" @submit.prevent="disconnect">
            <label class="label" for="sso-password">Enter your password to disconnect</label>
            <input id="sso-password" v-model="disconnectForm.password" type="password" class="input" autocomplete="current-password" />
            <p v-if="disconnectForm.errors.password" class="text-xs text-red-600" role="alert">{{ disconnectForm.errors.password }}</p>
            <div class="flex gap-2"><button class="btn-primary" :disabled="!disconnectForm.password">Disconnect</button><button type="button" class="btn-ghost" @click="disconnecting = null">Cancel</button></div>
        </form>
    </section>
    </div>
</template>
