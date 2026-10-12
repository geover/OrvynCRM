<script setup>
import SettingsShell from '@/Components/SettingsShell.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import LoginArt from '@/Components/LoginArt.vue';
import { confirmDialog, toast } from '@/lib/ui';

const props = defineProps({ branding: Object });
const page = usePage();
const PRESETS = ['#4f46e5', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#c026d3', '#475569'];
const form = useForm({ name: props.branding.name, tagline: props.branding.tagline ?? '', color: props.branding.color, theme: props.branding.theme ?? 'light' });
const THEMES = [['light', 'Light', 'Always light'], ['dark', 'Dark', 'Always dark'], ['system', 'Follow device', 'Light or dark, as each visitor\'s device is set']];

const save = () => form.patch('/platform/branding', { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved') });
function upload(e) {
    const file = e.target.files[0];
    if (!file) return;
    router.post('/platform/branding/logo', { logo: file }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => toast('Logo updated'),
        onError: (errors) => toast(errors.logo ?? 'Upload failed', 'error'),
    });
    e.target.value = '';
}
function uploadBackground(e) {
    const file = e.target.files[0];
    if (!file) return;
    router.post('/platform/branding/background', { background: file }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => toast('Background updated'),
        onError: (errors) => toast(errors.background ?? 'Upload failed', 'error'),
    });
    e.target.value = '';
}
function uploadFavicon(e) {
    const file = e.target.files[0];
    if (!file) return;
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

    <div class="grid gap-6 lg:grid-cols-2">
        <form class="card space-y-4 p-5" @submit.prevent="save">
            <h2 class="text-sm font-semibold">Text and colour</h2>
            <div><label class="label" for="pb-name">Product name</label><input id="pb-name" v-model="form.name" class="input" maxlength="60" required /><p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p></div>
            <div><label class="label" for="pb-tagline">Tagline <span class="normal-case text-slate-400">(leave empty to hide)</span></label><input id="pb-tagline" v-model="form.tagline" class="input" maxlength="120" /><p v-if="form.errors.tagline" class="mt-1 text-xs text-red-600">{{ form.errors.tagline }}</p></div>
            <div>
                <label class="label" for="pb-color">Colour</label>
                <div class="flex items-center gap-3"><input v-model="form.color" type="color" class="h-12 w-16 cursor-pointer rounded border border-slate-200" aria-label="Pick a colour" /><input id="pb-color" v-model="form.color" class="input !w-32 font-mono" maxlength="7" /></div>
                <div class="mt-2 flex flex-wrap gap-2"><button v-for="c in PRESETS" :key="c" type="button" :style="{ background: c }" class="size-8 rounded-full ring-offset-2 hover:ring-2" :class="form.color === c && 'ring-2 ring-slate-400'" :aria-label="`Use ${c}`" @click="form.color = c" /></div>
                <p v-if="form.errors.color" class="mt-1 text-xs text-red-600">{{ form.errors.color }}</p>
            </div>
            <fieldset>
                <legend class="label">Theme of the sign-in pages</legend>
                <div class="grid gap-2 sm:grid-cols-3" role="radiogroup">
                    <label v-for="[value, label, hint] in THEMES" :key="value" :class="form.theme === value ? 'border-brand-600 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'" class="flex cursor-pointer flex-col rounded-lg border p-3 text-sm">
                        <span class="flex items-center gap-2 font-medium"><input v-model="form.theme" type="radio" name="signin-theme" :value="value" class="accent-brand-600" /> {{ label }}</span>
                        <span class="mt-1 text-xs text-slate-500">{{ hint }}</span>
                    </label>
                </div>
                <p class="mt-1 text-xs text-slate-400">The same for everybody who visits the sign-in, company chooser and password pages. A company's own theme or a person's choice inside the app never changes it.</p>
                <p v-if="form.errors.theme" class="mt-1 text-xs text-red-600">{{ form.errors.theme }}</p>
            </fieldset>
            <button class="btn-primary" :disabled="form.processing">Save</button>
        </form>

        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">Logo</h2>
            <div class="grid h-28 place-items-center rounded-lg" :style="{ background: form.color }"><img :src="branding.logo_url" alt="Platform logo" class="max-h-20 max-w-[80%] object-contain" /></div>
            <p class="text-xs text-slate-400">{{ branding.logo_is_default ? 'The built-in OrvynCRM logo.' : 'Your uploaded logo.' }} It is the default for every company that has not uploaded its own, and appears on the sign-in page. Companies can replace it for themselves. PNG, JPG or WebP, up to 2 MB; design it for a dark background.</p>
            <div class="flex gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" data-testid="platform-logo" @change="upload" /></label>
                <button v-if="!branding.logo_is_default" class="btn-ghost text-red-600" @click="removeLogo">Use built-in</button></div>
        </section>

        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">Favicon</h2>
            <div class="flex h-28 items-center justify-center gap-6 rounded-lg bg-slate-100"><img :src="branding.favicon_url" alt="Favicon" class="size-16 object-contain" /><img :src="branding.favicon_url" alt="" class="size-8 object-contain" /><img :src="branding.favicon_url" alt="" class="size-4 object-contain" /></div>
            <p class="text-xs text-slate-400">{{ branding.favicon_is_default ? 'The built-in OrvynCRM icon.' : 'Your uploaded icon.' }} The small icon in the browser tab, for every company. Square PNG, ICO or WebP, up to 512 KB (180x180 or larger is best).</p>
            <div class="flex gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp,.ico" class="hidden" data-testid="platform-favicon" @change="uploadFavicon" /></label>
                <button v-if="!branding.favicon_is_default" class="btn-ghost text-red-600" @click="removeFavicon">Use built-in</button></div>
        </section>

        <section class="card space-y-4 p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold">Background</h2>
            <div class="relative h-56 overflow-hidden rounded-lg bg-brand-900" :style="{ '--color-brand-900': `color-mix(in srgb, ${form.color} 28%, black)`, '--color-brand-700': `color-mix(in srgb, ${form.color} 85%, black)` }">
                <img v-if="branding.background_url" :src="branding.background_url" alt="Sign-in background" class="absolute inset-0 size-full object-cover" />
                <LoginArt v-else />
            </div>
            <p class="text-xs text-slate-400">{{ branding.background_url ? 'Your image is shown on the left of the sign-in page.' : 'The built-in illustration (an office meeting reviewing a growing progress chart) takes the colour above.' }} Upload your own PNG, JPG or WebP, up to 5 MB, wide images work best.</p>
            <div class="flex gap-2"><label class="btn-outline cursor-pointer">Upload background<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" data-testid="platform-background" @change="uploadBackground" /></label>
                <button v-if="branding.background_url" class="btn-ghost text-red-600" @click="removeBackground">Use built-in</button></div>
        </section>
    </div>
</SettingsShell>
</template>
