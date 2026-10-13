<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import DarkStylePicker from '@/Components/DarkStylePicker.vue';
import LoginArt from '@/Components/LoginArt.vue';
import LoginBackground from '@/Components/LoginBackground.vue';
import { confirmDialog, toast } from '@/lib/ui';

const props = defineProps({ branding: Object, upload_limit: Number });
// A file over the server's upload limit never reaches the app, so say so before sending it.
const tooBig = (e, file) => {
    if (!props.upload_limit || file.size <= props.upload_limit) return false;
    toast(`This server accepts uploads up to ${Math.floor(props.upload_limit / 1048576)} MB and that file is ${(file.size / 1048576).toFixed(1)} MB. Use a smaller file, or ask your administrator to raise upload_max_filesize and post_max_size.`, 'error', 9000);
    e.target.value = '';
    return true;
};
const page = usePage();
// the white plate is for an uploaded logo with dark lettering: the built-in logo is white on dark and would vanish on it
const plateOn = computed(() => !!props.branding.logo_plate && !props.branding.logo_is_default);
const PRESETS = ['#4f46e5', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#c026d3', '#475569'];
const form = useForm({ name: props.branding.name, tagline: props.branding.tagline ?? '', color: props.branding.color, theme: props.branding.theme ?? 'light', dark_style: props.branding.dark_style ?? 'navy', ...props.branding.footer });
const FOOTER_LINKS = [['privacy_url', 'Privacy', 'https://www.example.com/privacy'], ['legal_url', 'Legal policies', 'https://www.example.com/legal'], ['terms_url', 'Terms of use', 'https://www.example.com/terms'], ['contact_url', 'Contact', 'https://www.example.com/contact or mailto:hello@example.com']];
const THEMES = [['light', 'Light', 'Always light'], ['dark', 'Dark', 'Always dark'], ['system', 'Follow device', 'Light or dark, as each visitor\'s device is set']];

// Like the logo, favicon and background on this page, the text, colour and theme save by themselves: a moment after the last change.
const saveState = ref('idle');          // idle | pending | saving | saved | error
let saveTimer = null;
const validToSave = () => form.name.trim() !== '' && /^#[0-9a-fA-F]{6}$/.test(form.color);
function save() {
    clearTimeout(saveTimer);
    if (!validToSave()) { saveState.value = 'idle'; return; }          // a half-typed colour or an empty name waits until it is complete
    saveState.value = 'saving';
    form.patch('/platform/branding', { preserveScroll: true, onSuccess: () => { saveState.value = 'saved'; toast(page.props.flash.success ?? 'Saved'); }, onError: () => { saveState.value = 'error'; } });
}
watch(() => [form.name, form.tagline, form.color, form.theme, form.dark_style, form.privacy_url, form.legal_url, form.terms_url, form.contact_url, form.address], () => { saveState.value = 'pending'; clearTimeout(saveTimer); saveTimer = setTimeout(save, 800); });
onBeforeUnmount(() => clearTimeout(saveTimer));
const SAVE_TEXT = { idle: 'Saved automatically', pending: 'Saving…', saving: 'Saving…', saved: 'Saved', error: 'Could not save: check the fields' };
function upload(e) {
    const file = e.target.files[0];
    if (!file || tooBig(e, file)) return;
    router.post('/platform/branding/logo', { logo: file }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => toast('Logo updated'),
        onError: (errors) => toast(errors.logo ?? 'Upload failed', 'error'),
    });
    e.target.value = '';
}
function uploadLight(e) {
    const file = e.target.files[0];
    if (!file || tooBig(e, file)) return;
    router.post('/platform/branding/logo-light', { logo: file }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => toast('Logo updated'),
        onError: (errors) => toast(errors.logo ?? 'Upload failed', 'error'),
    });
    e.target.value = '';
}
const removeLogoLight = () => router.delete('/platform/branding/logo-light', { preserveScroll: true, onSuccess: () => toast('Back to the built-in logo') });
function setLogoMono(on) {
    if (props.branding.logo_mono === on) return;
    router.patch('/platform/branding/logo-style', { logo_mono: on }, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved'), onError: () => toast('Could not save', 'error') });
}
function setLogoPlate(on) {
    router.patch('/platform/branding/logo-style', { logo_plate: on }, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved'), onError: () => toast('Could not save', 'error') });
}
function uploadBackground(e) {
    const file = e.target.files[0];
    if (!file || tooBig(e, file)) return;
    router.post('/platform/branding/background', { background: file }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => toast(page.props.flash.success ?? 'Background updated'),
        onError: (errors) => toast(errors.background ?? 'Upload failed', 'error'),
    });
    e.target.value = '';
}
function uploadFavicon(e) {
    const file = e.target.files[0];
    if (!file || tooBig(e, file)) return;
    router.post('/platform/branding/favicon', { favicon: file }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => toast('Favicon updated'),
        onError: (errors) => toast(errors.favicon ?? 'Upload failed', 'error'),
    });
    e.target.value = '';
}
async function removeFavicon() {
    if (!(await confirmDialog({ title: 'Go back to the built-in favicon?', confirmLabel: 'Remove', danger: true }))) return;
    router.delete('/platform/branding/favicon', { preserveScroll: true, onSuccess: () => toast('Favicon removed') });
}
async function removeBackground() {
    if (!(await confirmDialog({ title: 'Go back to the built-in background?', confirmLabel: 'Remove', danger: true }))) return;
    router.delete('/platform/branding/background', { preserveScroll: true, onSuccess: () => toast('Background removed') });
}
async function removeLogo() {
    if (!(await confirmDialog({ title: 'Remove the sign-in logo?', confirmLabel: 'Remove', danger: true }))) return;
    router.delete('/platform/branding/logo', { preserveScroll: true, onSuccess: () => toast('Logo removed') });
}
</script>

<template>
    <Head title="Sign-in page" />
    <SettingsShell title="Sign-in page" subtitle="One look for everybody: the login, company chooser and password pages. Companies cannot change it; their own look & feel applies only after sign-in.">

    <div class="space-y-6">
        <form class="card space-y-5 p-5" @submit.prevent="save">
            <div class="flex items-center justify-between gap-3"><h2 class="text-sm font-semibold">Text, colour and theme</h2><span class="text-xs" :class="saveState === 'error' ? 'text-red-600' : 'text-slate-400'" role="status" data-testid="autosave-status">{{ SAVE_TEXT[saveState] }}</span></div>
            <div class="grid gap-x-8 gap-y-5 lg:grid-cols-2">
            <div class="space-y-4">
            <div><label class="label" for="pb-name">Product name</label><input id="pb-name" v-model="form.name" class="input" maxlength="60" required /><p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p></div>
            <div><label class="label" for="pb-tagline">Tagline <span class="normal-case text-slate-400">(leave empty to hide)</span></label><input id="pb-tagline" v-model="form.tagline" class="input" maxlength="120" /><p v-if="form.errors.tagline" class="mt-1 text-xs text-red-600">{{ form.errors.tagline }}</p></div>
            <div>
                <label class="label" for="pb-color">Colour</label>
                <div class="flex items-center gap-3"><input v-model="form.color" type="color" class="h-12 w-16 cursor-pointer rounded border border-slate-200" aria-label="Pick a colour" /><input id="pb-color" v-model="form.color" class="input !w-32 font-mono" maxlength="7" /></div>
                <div class="mt-2 flex flex-wrap gap-2"><button v-for="c in PRESETS" :key="c" type="button" :style="{ background: c }" class="size-8 rounded-full ring-offset-2 hover:ring-2" :class="form.color === c && 'ring-2 ring-slate-400'" :aria-label="`Use ${c}`" @click="form.color = c" /></div>
                <p v-if="form.errors.color" class="mt-1 text-xs text-red-600">{{ form.errors.color }}</p>
            </div>
            </div>
            <div class="space-y-4">
            <fieldset>
                <legend class="label">Theme of the sign-in pages</legend>
                <div class="grid gap-2" role="radiogroup">
                    <label v-for="[value, label, hint] in THEMES" :key="value" :class="form.theme === value ? 'border-brand-600 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'" class="flex cursor-pointer flex-col rounded-lg border p-3 text-sm">
                        <span class="flex items-center gap-2 font-medium"><input v-model="form.theme" type="radio" name="signin-theme" :value="value" class="accent-brand-600" /> {{ label }}</span>
                        <span class="mt-1 text-xs text-slate-500">{{ hint }}</span>
                    </label>
                </div>
                <p class="mt-1 text-xs text-slate-400">The same for everybody who visits the sign-in, company chooser and password pages. A company's own theme or a person's choice inside the app never changes it.</p>
                <p v-if="form.errors.theme" class="mt-1 text-xs text-red-600">{{ form.errors.theme }}</p>
            </fieldset>
            <div v-if="form.theme !== 'light'" data-testid="signin-dark-style">
                <DarkStylePicker v-model="form.dark_style" start-open />
                <p class="mt-1 text-xs text-slate-400">The look of the sign-in pages when they are dark: always dark, or a visitor whose device is set to dark. {{ form.errors.dark_style }}</p>
            </div>
            </div>
            </div>
        </form>

        <section class="card space-y-4 p-5" data-testid="footer-card">
            <div class="flex items-center justify-between gap-3"><h2 class="text-sm font-semibold">Footer of the sign-in page</h2><span class="text-xs text-slate-400">Saved automatically</span></div>
            <p class="text-xs text-slate-500">Shown at the bottom left of the sign-in page, under "© {{ new Date().getFullYear() }} {{ form.name }}. All Rights Reserved." A link appears only when its address is filled in. Leave the business address empty to hide it.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <div v-for="[key, label, placeholder] in FOOTER_LINKS" :key="key"><label class="label" :for="`pb-${key}`">{{ label }}</label><input :id="`pb-${key}`" v-model="form[key]" class="input" :placeholder="placeholder" maxlength="255" :data-testid="`footer-${key}`" /><p v-if="form.errors[key]" class="mt-1 text-xs text-red-600">{{ form.errors[key] }}</p></div>
            </div>
            <div><label class="label" for="pb-address">Business address</label><textarea id="pb-address" v-model="form.address" rows="2" maxlength="300" class="input" placeholder="123 Main Street, Suite 4, City, Country" data-testid="footer-address" /><p v-if="form.errors.address" class="mt-1 text-xs text-red-600">{{ form.errors.address }}</p></div>
        </section>

        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">Logo</h2>
            <p class="text-xs text-slate-500">Two versions, so the logo is readable wherever it sits. They are the default for every company that has not uploaded its own logo. Companies can replace them for themselves. SVG, PNG, JPG or WebP, up to 2 MB each. An SVG is cleaned on upload: scripts and links to other sites are removed.</p>
            <div class="flex flex-wrap items-center gap-3" data-testid="logo-style">
                <span class="text-sm font-medium">Built-in logo</span>
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5" role="group" aria-label="Built-in logo style">
                    <button v-for="[value, label] in [[false, 'Full colour'], [true, 'Mono (one colour)']]" :key="label" type="button" :aria-pressed="branding.logo_mono === value" :data-testid="value ? 'logo-mono' : 'logo-colour'"
                        :class="branding.logo_mono === value ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'" class="rounded-md px-3 py-1 text-xs font-medium transition" @click="setLogoMono(value)">{{ label }}</button>
                </div>
                <span class="text-xs text-slate-500">Applies to the built-in logos below, not to files you upload. Mono is white on dark backgrounds and dark on light ones.</span>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="space-y-3 rounded-lg border border-slate-200 p-4" data-testid="logo-dark-box">
                    <div><h3 class="text-sm font-medium">For dark backgrounds</h3><p class="text-xs text-slate-500">White or light lettering. Used on the sign-in page and on dark sidebars.</p></div>
                    <div class="grid h-28 place-items-center rounded-lg" :style="{ background: form.color }"><div :class="plateOn && 'rounded-xl bg-[#fffffff2] px-4 py-2.5 shadow-lg ring-1 ring-black/5'"><img :src="branding.logo_url" alt="Logo for dark backgrounds" class="h-14 w-auto object-contain" /></div></div>
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5 size-4 accent-brand-600 disabled:opacity-40" :checked="plateOn" :disabled="branding.logo_is_default" data-testid="logo-plate" @change="setLogoPlate($event.target.checked)" />
                        <span :class="branding.logo_is_default && 'opacity-60'"><span class="font-medium">Show it on a white plate</span><span class="block text-xs text-slate-500">{{ branding.logo_is_default ? 'Only for a logo you upload. The built-in logo has light lettering and needs no plate.' : 'For a logo with dark lettering that is hard to read over the sign-in picture or video.' }}</span></span></label>
                    <p class="text-xs text-slate-400">{{ branding.logo_is_default ? 'The built-in OrvynCRM logo.' : 'Your uploaded logo.' }}</p>
                    <div class="flex gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg" class="hidden" data-testid="platform-logo" @change="upload" /></label>
                        <button v-if="!branding.logo_is_default" class="btn-ghost text-red-600" @click="removeLogo">Use built-in</button></div>
                </div>
                <div class="space-y-3 rounded-lg border border-slate-200 p-4" data-testid="logo-light-box">
                    <div><h3 class="text-sm font-medium">For light backgrounds</h3><p class="text-xs text-slate-500">Dark lettering. Used where the page or sidebar is light.</p></div>
                    <div class="grid h-28 place-items-center rounded-lg border border-slate-200 bg-[#ffffff]"><img :src="branding.logo_light_url" alt="Logo for light backgrounds" class="h-14 w-auto object-contain" /></div>
                    <p class="text-xs text-slate-400">{{ branding.logo_light_is_default ? 'The built-in OrvynCRM logo.' : 'Your uploaded logo.' }}</p>
                    <div class="flex gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg" class="hidden" data-testid="platform-logo-light" @change="uploadLight" /></label>
                        <button v-if="!branding.logo_light_is_default" class="btn-ghost text-red-600" @click="removeLogoLight">Use built-in</button></div>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">Favicon</h2>
            <div class="flex h-28 items-center justify-center gap-6 rounded-lg bg-slate-100"><img :src="branding.favicon_url" alt="Favicon" class="size-16 object-contain" /><img :src="branding.favicon_url" alt="" class="size-8 object-contain" /><img :src="branding.favicon_url" alt="" class="size-4 object-contain" /></div>
            <p class="text-xs text-slate-400">{{ branding.favicon_is_default ? 'The built-in OrvynCRM icon.' : 'Your uploaded icon.' }} The small icon in the browser tab, for every company. Square SVG, PNG, ICO or WebP, up to 512 KB (an SVG, or 180x180 or larger, is best).</p>
            <div class="flex gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp,image/svg+xml,.ico,.svg" class="hidden" data-testid="platform-favicon" @change="uploadFavicon" /></label>
                <button v-if="!branding.favicon_is_default" class="btn-ghost text-red-600" @click="removeFavicon">Use built-in</button></div>
        </section>

        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">Background</h2>
            <div class="relative h-56 overflow-hidden rounded-lg bg-brand-900" :style="{ '--color-brand-900': `color-mix(in srgb, ${form.color} 28%, black)`, '--color-brand-700': `color-mix(in srgb, ${form.color} 85%, black)` }">
                <LoginBackground v-if="branding.background_url" :url="branding.background_url" :type="branding.background_type" />
                <LoginArt v-else />
            </div>
            <p class="text-xs text-slate-400">{{ branding.background_is_default ? (branding.background_url ? 'The built-in video plays silently on a loop on the left of the sign-in page.' : 'The built-in illustration (an office meeting reviewing a growing progress chart) takes the colour above.') : (branding.background_type === 'video' ? 'Your video plays silently on a loop on the left of the sign-in page.' : 'Your image is shown on the left of the sign-in page.') }} Upload your own image (PNG, JPG or WebP, up to 5 MB) or a short video (MP4 or WebM, up to 25 MB; it plays without sound and repeats). Wide pictures work best, and a video should be a few seconds to half a minute long.</p>
            <div class="flex gap-2"><label class="btn-outline cursor-pointer">Upload background<input type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/webm,.mp4,.webm" class="hidden" data-testid="platform-background" @change="uploadBackground" /></label>
                <button v-if="!branding.background_is_default" class="btn-ghost text-red-600" @click="removeBackground">Use built-in</button></div>
        </section>
        </div>
    </div>
</SettingsShell>
</template>
