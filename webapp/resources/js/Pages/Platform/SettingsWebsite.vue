<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import PasswordField from '@/Components/PasswordField.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { fmtRelative, toast } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// What the public website says that is decided here. The website reads it when it is built; empty fields keep the website's own default.
const props = defineProps({ website: Object, feed: String, effective: Object });
const page = usePage();
const done = () => {
    toast(page.props.flash.success ?? 'Saved', 'success');
    if (page.props.flash.warning) toast(page.props.flash.warning, 'error', 12000);
};

const form = useForm({
    contact_email: props.website.contact_email, business_address: props.website.business_address, legal_entity: props.website.legal_entity,
    legal_effective: props.website.legal_effective, legal_updated: props.website.legal_updated, demo_form_url: props.website.demo_form_url,
    build_hook: '', clear_hook: false,
});
const save = () => form.patch('/platform/settings/website', {
    preserveScroll: true,
    onSuccess: () => { form.build_hook = ''; form.clear_hook = false; done(); },
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save', 'error'),
});
const rebuilding = useForm({});
const rebuild = () => rebuilding.post('/platform/settings/website/rebuild', { preserveScroll: true, onSuccess: done, onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not rebuild', 'error') });
const copy = async () => { try { await navigator.clipboard.writeText(props.feed); toast('Address copied', 'success'); } catch { toast('Select the address and copy it', 'error'); } };
const LABELS = { contact_email: 'Contact e-mail', business_address: 'Business address', legal_entity: 'Legal entity', legal_effective: 'Legal pages effective', legal_updated: 'Legal pages updated', demo_form_url: 'Demo form' };
</script>

<template>
    <Head title="Website" />
    <SettingsShell title="Website" subtitle="What the public website says that is decided here. The website reads it when it is built; anything left empty keeps the website's own default from its .env file.">
        <form class="card space-y-4 p-5" data-testid="website-form" @submit.prevent="save">
            <h2 class="text-sm font-semibold">Contact and company details</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ws-email">Contact e-mail</label><input id="ws-email" v-model="form.contact_email" type="email" class="input" placeholder="hello@yourcompany.com" data-testid="website-email" /><p class="mt-1 text-xs text-slate-500">Shown in the footer, on the demo page and in the contact options.</p><p v-if="form.errors.contact_email" class="mt-1 text-xs text-red-600">{{ form.errors.contact_email }}</p></div>
                <div><label class="label" for="ws-entity">Legal entity</label><input id="ws-entity" v-model="form.legal_entity" class="input" placeholder="Your registered company name" data-testid="website-entity" /><p class="mt-1 text-xs text-slate-500">The company named in the Privacy Policy and Terms of Use.</p><p v-if="form.errors.legal_entity" class="mt-1 text-xs text-red-600">{{ form.errors.legal_entity }}</p></div>
            </div>
            <div><label class="label" for="ws-address">Business address</label><textarea id="ws-address" v-model="form.business_address" class="input min-h-20" placeholder="Street, city, country" data-testid="website-address" /><p class="mt-1 text-xs text-slate-500">Printed in the footer and the legal pages. Leave empty to leave it out.</p><p v-if="form.errors.business_address" class="mt-1 text-xs text-red-600">{{ form.errors.business_address }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ws-eff">Legal pages effective from</label><input id="ws-eff" v-model="form.legal_effective" type="date" class="input" data-testid="website-effective" /><p v-if="form.errors.legal_effective" class="mt-1 text-xs text-red-600">{{ form.errors.legal_effective }}</p></div>
                <div><label class="label" for="ws-upd">Legal pages last updated</label><input id="ws-upd" v-model="form.legal_updated" type="date" class="input" data-testid="website-updated" /><p v-if="form.errors.legal_updated" class="mt-1 text-xs text-red-600">{{ form.errors.legal_updated }}</p></div>
            </div>
            <div><label class="label" for="ws-demo">Demo form address</label><input id="ws-demo" v-model="form.demo_form_url" class="input font-mono text-xs" placeholder="https://app.yourcompany.com/f/FORM-ID.js" data-testid="website-demo" /><p class="mt-1 text-xs text-slate-500">The embed address (ending in .js) of a form built in the Form builder. Requests then arrive as contacts in your own CRM. Empty: the demo page shows an e-mail button instead.</p><p v-if="form.errors.demo_form_url" class="mt-1 text-xs text-red-600">{{ form.errors.demo_form_url }}</p></div>

            <h2 class="border-t border-slate-100 pt-4 text-sm font-semibold">Rebuild the website automatically</h2>
            <div>
                <label class="label" for="ws-hook">Build hook <span class="normal-case text-slate-400">(optional)</span></label>
                <PasswordField id="ws-hook" v-model="form.build_hook" autocomplete="off" :placeholder="website.hook_set ? 'Saved. Type to replace it.' : 'https://api.netlify.com/build_hooks/…'" />
                <label v-if="website.hook_set" class="mt-1 flex items-center gap-2 text-xs text-slate-500"><input v-model="form.clear_hook" type="checkbox" class="accent-brand-600" /> Remove the saved hook</label>
                <p class="mt-1 text-xs text-slate-500">Netlify, Cloudflare Pages and Vercel each give you an address that builds the site when it is called. With one saved, a change here rebuilds the website by itself. It is stored encrypted and never shown again.</p>
                <p v-if="form.errors.build_hook" class="mt-1 text-xs text-red-600" data-testid="website-hook-error">{{ form.errors.build_hook }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button class="btn-primary" :disabled="form.processing" data-testid="save-website">Save</button>
                <button type="button" class="btn-outline" :disabled="!website.hook_set || rebuilding.processing" data-testid="rebuild-website" @click="rebuild"><RefreshCw class="size-4" /> Rebuild the website now</button>
                <span v-if="website.last_rebuild" class="text-xs" :class="website.last_rebuild.ok ? 'text-emerald-700' : 'text-amber-700'" data-testid="rebuild-status">Last request {{ fmtRelative(website.last_rebuild.at) }}: {{ website.last_rebuild.message }}</span>
            </div>
        </form>

        <section class="card space-y-3 p-5" aria-labelledby="ws-feed">
            <h2 id="ws-feed" class="text-sm font-semibold">What the website reads</h2>
            <p class="text-xs text-slate-500">The website fetches this public address when it is built. It holds only what the website prints anyway; a field showing <em>not set</em> keeps the website's own default.</p>
            <div class="flex flex-wrap items-center gap-2"><code class="break-all rounded bg-slate-100 px-2 py-1 text-xs" data-testid="website-feed">{{ feed }}</code><button type="button" class="btn-outline !py-1 text-xs" @click="copy">Copy</button></div>
            <dl class="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2" data-testid="website-effective-values">
                <div v-for="(label, key) in LABELS" :key="key" class="flex items-baseline justify-between gap-3 border-b border-slate-100 py-1"><dt class="text-slate-500">{{ label }}</dt><dd class="truncate text-right font-medium" :class="!effective[key] && 'text-slate-400'">{{ effective[key] ?? 'not set' }}</dd></div>
            </dl>
        </section>
    </SettingsShell>
</template>
