<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import PasswordField from '@/Components/PasswordField.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// Google reCAPTCHA v3: invisible, scores each sign-in and sign-up attempt, and turns away the ones that look like a script.
const props = defineProps({ recaptcha: Object, active: Boolean });
const page = usePage();
const done = () => toast(page.props.flash.success ?? 'Saved');

const form = useForm({ enabled: props.recaptcha.enabled, site_key: props.recaptcha.site_key, secret: '', clear_secret: false, threshold: props.recaptcha.threshold });
const save = () => form.patch('/platform/settings/bot-protection', {
    preserveScroll: true,
    onSuccess: () => { form.secret = ''; form.clear_secret = false; done(); },
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save the settings', 'error'),
});
const strictness = (v) => (v <= 0.3 ? 'Lenient: only the most obvious bots are refused' : v >= 0.7 ? 'Strict: some real people may be asked to try again' : 'Balanced (Google\'s suggested starting point)');
</script>

<template>
    <Head title="Bot protection" />
    <SettingsShell title="Bot protection" subtitle="Google reCAPTCHA v3 scores every sign-in and sign-up attempt without asking anyone to solve a puzzle. Scripts that score too low are turned away.">
        <form class="card space-y-4 p-5" data-testid="recaptcha-form" @submit.prevent="save">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">reCAPTCHA v3</h2>
                <span class="badge" :class="active ? '!bg-emerald-50 !text-emerald-700' : form.enabled || recaptcha.enabled ? '!bg-amber-50 !text-amber-700' : ''" data-testid="recaptcha-status">{{ active ? 'On: protecting sign-in and sign-up' : recaptcha.enabled ? 'Incomplete: keys missing' : 'Off' }}</span>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium"><input v-model="form.enabled" type="checkbox" class="size-4 accent-brand-600" data-testid="recaptcha-enabled" /> Check the sign-in and sign-up pages with reCAPTCHA</label>

            <fieldset :disabled="!form.enabled" :class="!form.enabled && 'opacity-50'" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label" for="rc-site">Site key</label><input id="rc-site" v-model="form.site_key" class="input font-mono text-xs" autocomplete="off" placeholder="6L…" data-testid="recaptcha-site" /><p v-if="form.errors.site_key" class="mt-1 text-xs text-red-600">{{ form.errors.site_key }}</p></div>
                    <div><label class="label" for="rc-secret">Secret key</label><PasswordField id="rc-secret" v-model="form.secret" autocomplete="new-password" :placeholder="recaptcha.secret_set ? 'Saved. Type to replace it.' : ''" />
                        <label v-if="recaptcha.secret_set" class="mt-1 flex items-center gap-2 text-xs text-slate-500"><input v-model="form.clear_secret" type="checkbox" class="accent-brand-600" /> Remove the saved key</label></div>
                </div>
                <div>
                    <label class="label" for="rc-threshold">How strict <span class="normal-case text-slate-400">(minimum score, 0.1 to 0.9)</span></label>
                    <div class="flex items-center gap-3"><input id="rc-threshold" v-model.number="form.threshold" type="range" min="0.1" max="0.9" step="0.1" class="w-64 accent-brand-600" data-testid="recaptcha-threshold" /><span class="w-10 font-mono text-sm tabular-nums">{{ Number(form.threshold).toFixed(1) }}</span></div>
                    <p class="mt-1 text-xs text-slate-500">{{ strictness(form.threshold) }}</p>
                    <p v-if="form.errors.threshold" class="mt-1 text-xs text-red-600">{{ form.errors.threshold }}</p>
                </div>
            </fieldset>
            <p class="text-xs text-slate-400">The secret key is stored encrypted and never shown again. A person who is refused sees "We could not confirm that you are a person" and can try again; a refusal is never counted as a wrong password.</p>
            <button class="btn-primary" :disabled="form.processing" data-testid="save-recaptcha">Save</button>
        </form>

        <section class="card p-5" aria-labelledby="rc-how">
            <h2 id="rc-how" class="text-sm font-semibold">Getting the keys</h2>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
                <li>Open <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener noreferrer" class="text-brand-700 underline">the reCAPTCHA admin console</a> and create a site.</li>
                <li>Choose <strong>reCAPTCHA v3</strong> (score based), and add the address people use to sign in, for example the app's domain.</li>
                <li>Paste the <strong>site key</strong> and <strong>secret key</strong> above, tick the box, and save. The sign-in and sign-up pages then load Google's script and carry a small "Protected by reCAPTCHA" note.</li>
            </ol>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
                <li>Add each address you use (including a local one such as <code class="rounded bg-slate-100 px-1">app.orvyncrm.test</code>) to the site's domains, or Google refuses the check there.</li>
                <li>If Google itself cannot be reached, people are let through (and it is logged): an outage must not lock everybody out. The throttles and lockouts still apply.</li>
                <li>While it is off, nothing is requested from Google.</li>
            </ul>
        </section>
    </SettingsShell>
</template>
