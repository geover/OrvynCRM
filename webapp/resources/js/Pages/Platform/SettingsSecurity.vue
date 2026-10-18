<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Fingerprint } from 'lucide-vue-next';
import PasswordField from '@/Components/PasswordField.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';

// How people sign in. Two-step verification and passkeys are set up by each person on their own sign-in security page; this decides who must
// and whether passkeys are offered. Each identity provider needs the keys the provider gives out, entered once here.
const props = defineProps({ policy: String, adoption: Object, passkeys: Boolean, social: Array });
const page = usePage();
const done = () => toast(page.props.flash.success ?? 'Saved');
const OPTIONS = [
    ['optional', 'Optional for everybody', 'Anybody can turn it on in their own sign-in security page.'],
    ['platform_admins', 'Required for platform administrators', 'Platform administrators must set it up; everybody else may.'],
    ['admins', 'Required for administrators', 'Platform administrators and the administrators of every company must set it up.'],
    ['everyone', 'Required for everybody', 'Every person who signs in must set it up before doing anything else.'],
];
const policyForm = useForm({ policy: props.policy });
const savePolicy = () => policyForm.patch('/platform/settings/security', { preserveScroll: true, onSuccess: done });
const togglePasskeys = (enabled) => router.patch('/platform/settings/security/passkeys', { enabled }, { preserveScroll: true, onSuccess: done });

// one form per provider
const HELP = {
    google: ['Open the Google Cloud console, then APIs & Services, Credentials, and create an OAuth client ID of type "Web application".', 'Add the callback address below as an authorised redirect URI, then paste the client ID and secret.'],
    microsoft: ['In the Microsoft Entra admin centre, register an application (App registrations) and add the callback address below as a Web redirect URI.', 'Create a client secret, then paste the application (client) ID, the secret and your directory (tenant) ID. Only people of that directory can use it: Microsoft does not vouch for the e-mail of outside accounts.'],
    github: ['In GitHub, Settings, Developer settings, OAuth Apps, create an app and use the callback address below as its authorization callback URL.', 'Paste the client ID and a generated client secret. People are matched by their primary verified GitHub e-mail.'],
    oidc: ['Any provider that speaks OpenID Connect: Okta, Auth0, Keycloak, Entra ID, a company\'s own single sign-on.', 'Register this application there with the callback address below, then paste the provider\'s address (the issuer, for example https://acme.okta.com), the client ID and the secret.'],
};
const forms = Object.fromEntries(props.social.map((p) => [p.key, useForm({ enabled: p.enabled, client_id: p.client_id, client_secret: '', clear_secret: false, tenant: p.tenant, issuer: p.issuer, label: p.label })]));
const saveProvider = (p) => forms[p.key].patch(`/platform/settings/security/social/${p.key}`, {
    preserveScroll: true,
    onSuccess: () => { forms[p.key].client_secret = ''; forms[p.key].clear_secret = false; done(); },
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save', 'error'),
});
const copy = async (text) => { await navigator.clipboard?.writeText(text); toast('Copied'); };
const status = (p) => (p.active ? ['On', '!bg-emerald-50 !text-emerald-700'] : p.enabled ? ['Incomplete', '!bg-amber-50 !text-amber-700'] : ['Off', '']);
</script>

<template>
    <Head title="Sign-in security" />
    <SettingsShell title="Sign-in security" subtitle="How people prove it is them: a code from an authenticator app, a passkey on their device, or an account they already have at Google, Microsoft, GitHub or your own single sign-on.">
        <form class="card space-y-4 p-5" data-testid="two-factor-policy-form" @submit.prevent="savePolicy">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-sm font-semibold">Two-step verification</h2><span class="text-sm text-slate-500" data-testid="two-factor-adoption">{{ adoption.enrolled }} of {{ adoption.total }} active people use it</span></div>
            <fieldset class="space-y-2">
                <legend class="sr-only">Who must use it</legend>
                <label v-for="[value, label, text] in OPTIONS" :key="value" class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 hover:border-brand-500" :class="policyForm.policy === value && 'border-brand-500 bg-brand-50/40'">
                    <input v-model="policyForm.policy" type="radio" :value="value" class="mt-1 accent-brand-600" :data-testid="`policy-${value}`" />
                    <span><span class="block text-sm font-medium">{{ label }}</span><span class="block text-xs text-slate-500">{{ text }}</span></span>
                </label>
            </fieldset>
            <p class="text-xs text-slate-500">When it is required, a person who has not set it up yet is taken to their sign-in security page the next time they open a page, and can do nothing else until they have. A passkey counts too. Somebody who loses their phone uses a recovery code; if they lose those too, an administrator can reset it for them.</p>
            <button class="btn-primary" :disabled="policyForm.processing || policyForm.policy === policy" data-testid="save-two-factor-policy">Save</button>
        </form>

        <section class="card space-y-3 p-5" aria-labelledby="pk-set" data-testid="passkeys-setting">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="pk-set" class="flex items-center gap-2 text-sm font-semibold"><Fingerprint class="size-4" /> Passkeys</h2><span class="badge" :class="passkeys ? '!bg-emerald-50 !text-emerald-700' : ''" data-testid="passkeys-status">{{ passkeys ? 'On' : 'Off' }}</span></div>
            <p class="text-sm text-slate-600">Let people sign in with the fingerprint, face or PIN of their phone or laptop, or a security key. Nothing secret is stored here (only a public key), a passkey cannot be phished, and it counts as the second step as well.</p>
            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" class="size-4 accent-brand-600" :checked="passkeys" data-testid="passkeys-enabled" @change="togglePasskeys($event.target.checked)" /> Offer passkeys on the sign-in page and in each person's sign-in security page</label>
            <p class="text-xs text-slate-500">Passkeys are tied to the address people use to sign in. If you change that address, passkeys made on the old one stop working and people add them again.</p>
        </section>

        <section v-for="p in social" :key="p.key" class="card space-y-3 p-5" :aria-labelledby="`sso-${p.key}`" :data-testid="`provider-${p.key}`">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 :id="`sso-${p.key}`" class="text-sm font-semibold">{{ p.key === 'oidc' ? 'Custom OpenID Connect' : p.default_name }}</h2><span class="badge" :class="status(p)[1]" :data-testid="`provider-status-${p.key}`">{{ status(p)[0] }}</span></div>
            <form class="space-y-3" @submit.prevent="saveProvider(p)">
                <label class="flex items-center gap-2 text-sm font-medium"><input v-model="forms[p.key].enabled" type="checkbox" class="size-4 accent-brand-600" :data-testid="`provider-enabled-${p.key}`" /> Offer "Continue with {{ p.key === 'oidc' && forms[p.key].label ? forms[p.key].label : p.default_name }}" on the sign-in page</label>
                <fieldset :disabled="!forms[p.key].enabled" :class="!forms[p.key].enabled && 'opacity-50'" class="space-y-3">
                    <div v-if="p.key === 'oidc'" class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label" :for="`${p.key}-issuer`">Provider address (issuer)</label><input :id="`${p.key}-issuer`" v-model="forms[p.key].issuer" class="input font-mono text-xs" placeholder="https://acme.okta.com" :data-testid="`provider-issuer-${p.key}`" /><p v-if="forms[p.key].errors.issuer" class="mt-1 text-xs text-red-600">{{ forms[p.key].errors.issuer }}</p></div>
                        <div><label class="label" :for="`${p.key}-label`">Button label</label><input :id="`${p.key}-label`" v-model="forms[p.key].label" class="input" maxlength="40" placeholder="Acme SSO" /></div>
                    </div>
                    <div v-if="p.key === 'microsoft'"><label class="label" :for="`${p.key}-tenant`">Directory (tenant) ID or domain</label><input :id="`${p.key}-tenant`" v-model="forms[p.key].tenant" class="input font-mono text-xs" placeholder="contoso.onmicrosoft.com" :data-testid="`provider-tenant-${p.key}`" /><p v-if="forms[p.key].errors.tenant" class="mt-1 text-xs text-red-600">{{ forms[p.key].errors.tenant }}</p></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label" :for="`${p.key}-id`">Client ID</label><input :id="`${p.key}-id`" v-model="forms[p.key].client_id" class="input font-mono text-xs" autocomplete="off" :data-testid="`provider-client-id-${p.key}`" /><p v-if="forms[p.key].errors.client_id" class="mt-1 text-xs text-red-600">{{ forms[p.key].errors.client_id }}</p></div>
                        <div>
                            <label class="label" :for="`${p.key}-secret`">Client secret</label>
                            <PasswordField :id="`${p.key}-secret`" v-model="forms[p.key].client_secret" autocomplete="new-password" :placeholder="p.secret_set ? 'Saved: type to replace it' : ''" />
                            <label v-if="p.secret_set" class="mt-1 flex items-center gap-2 text-xs text-slate-500"><input v-model="forms[p.key].clear_secret" type="checkbox" class="accent-brand-600" /> Remove the saved secret</label>
                        </div>
                    </div>
                </fieldset>
                <div>
                    <label class="label" :for="`${p.key}-cb`">Callback address (give this to {{ p.key === 'oidc' ? 'the provider' : p.default_name }})</label>
                    <div class="flex gap-2"><input :id="`${p.key}-cb`" :value="p.callback" readonly class="input font-mono text-xs" :data-testid="`provider-callback-${p.key}`" /><button type="button" class="btn-outline shrink-0" aria-label="Copy the callback address" @click="copy(p.callback)"><Copy class="size-4" /></button></div>
                </div>
                <button class="btn-primary" :disabled="forms[p.key].processing" :data-testid="`save-provider-${p.key}`">Save</button>
            </form>
            <details class="text-sm text-slate-600"><summary class="cursor-pointer text-brand-700">How to set it up</summary><ol class="mt-2 list-decimal space-y-1 pl-5"><li v-for="line in HELP[p.key]" :key="line">{{ line }}</li></ol></details>
        </section>

        <p class="text-xs text-slate-500">People are matched to an account that already exists by a <strong>verified</strong> e-mail address; nobody can create an account this way. If the person uses two-step verification, the code is still asked for after a provider sign-in.</p>
    </SettingsShell>
</template>
