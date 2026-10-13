<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';

// The look of the platform area itself (this menu, its buttons and highlights, the browser-tab icon). It is separate from the sign-in page:
// whatever is left empty here follows the sign-in page, so nothing changes until you set something.
const props = defineProps({ look: Object, upload_limit: Number });
const page = usePage();
const PRESETS = ['#4f46e5', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#c026d3', '#475569'];

const form = useForm({ name: props.look.name, color: props.look.color });
const state = ref('idle');
let timer = null;
const colorOk = () => form.color === '' || /^#[0-9a-fA-F]{6}$/.test(form.color);
function save() {
    clearTimeout(timer);
    if (!colorOk()) { state.value = 'idle'; return; }
    state.value = 'saving';
    form.patch('/platform/look', { preserveScroll: true, onSuccess: () => { state.value = 'saved'; toast(page.props.flash.success ?? 'Saved'); }, onError: () => { state.value = 'error'; } });
}
watch(() => [form.name, form.color], () => { state.value = 'pending'; clearTimeout(timer); timer = setTimeout(save, 800); });
onBeforeUnmount(() => clearTimeout(timer));
const SAVE_TEXT = { idle: 'Saved automatically', pending: 'Saving…', saving: 'Saving…', saved: 'Saved', error: 'Could not save: check the fields' };

const tooBig = (e, file) => {
    if (!props.upload_limit || file.size <= props.upload_limit) return false;
    toast(`This server accepts uploads up to ${Math.floor(props.upload_limit / 1048576)} MB and that file is ${(file.size / 1048576).toFixed(1)} MB.`, 'error', 9000);
    e.target.value = '';
    return true;
};
function upload(kind, e) {
    const file = e.target.files[0];
    if (!file || tooBig(e, file)) return;
    router.post(`/platform/look/${kind}`, { [kind]: file }, { forceFormData: true, preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Updated'), onError: (errors) => toast(errors[kind] ?? 'Upload failed', 'error') });
    e.target.value = '';
}
const remove = (kind) => router.delete(`/platform/look/${kind}`, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Removed') });
</script>

<template>
    <Head title="Platform look" />
    <SettingsShell title="Platform look" subtitle="How the platform area looks to you and the other platform administrators: its menu, buttons and highlights, and the browser-tab icon. Separate from the sign-in page.">
        <div class="space-y-6">
            <p class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="look-note">Anything left empty here follows the <Link href="/platform/branding" class="font-medium underline">Sign-in page</Link> settings, so nothing changes until you set it. The light or dark theme of this area is your own choice, in the menu footer.</p>

            <section class="card space-y-4 p-5" aria-labelledby="lk-text">
                <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="lk-text" class="text-sm font-semibold">Name and colour</h2><span class="text-xs text-slate-400" data-testid="look-save-state">{{ SAVE_TEXT[state] }}</span></div>
                <div>
                    <label class="label" for="lk-name">Name shown at the top of the menu <span class="normal-case text-slate-400">(empty = “{{ look.name_default }}”, from the sign-in page)</span></label>
                    <input id="lk-name" v-model="form.name" class="input sm:!w-80" maxlength="60" :placeholder="look.name_default" data-testid="look-name" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="label" for="lk-color">Colour <span class="normal-case text-slate-400">(empty = the sign-in page's {{ look.color_default }})</span></label>
                    <div class="flex flex-wrap items-center gap-2">
                        <input id="lk-color" v-model="form.color" class="input !w-32 font-mono text-sm" maxlength="7" :placeholder="look.color_default" data-testid="look-color" />
                        <span class="size-7 rounded-full ring-1 ring-black/10" :style="{ background: colorOk() && form.color ? form.color : look.color_default }" aria-hidden="true" />
                        <button v-for="c in PRESETS" :key="c" type="button" class="size-6 rounded-full ring-1 ring-black/10" :style="{ background: c }" :aria-label="`Use ${c}`" @click="form.color = c" />
                        <button v-if="form.color" type="button" class="btn-ghost !py-1 text-xs" data-testid="look-color-reset" @click="form.color = ''">Use the sign-in colour</button>
                    </div>
                    <p v-if="!colorOk()" class="mt-1 text-xs text-red-600">A colour looks like #4f46e5.</p><p v-if="form.errors.color" class="mt-1 text-xs text-red-600">{{ form.errors.color }}</p>
                </div>
            </section>

            <div class="grid gap-6 sm:grid-cols-2">
                <section class="card space-y-3 p-5" aria-labelledby="lk-mark">
                    <h2 id="lk-mark" class="text-sm font-semibold">Logo mark</h2>
                    <p class="text-xs text-slate-500">The small picture beside the name at the top of the menu. Square works best. PNG, JPG, WebP or SVG.</p>
                    <div class="flex items-center gap-4"><span class="grid size-14 place-items-center rounded-lg border border-slate-200 bg-white"><img :src="look.mark_url" alt="Current logo mark" class="size-10 object-contain" data-testid="look-mark" /></span>
                        <div class="flex flex-wrap gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg" class="sr-only" data-testid="look-logo-file" @change="upload('logo', $event)" /></label><button v-if="look.mark_custom" class="btn-ghost" data-testid="look-logo-remove" @click="remove('logo')">Use the built-in one</button></div></div>
                </section>
                <section class="card space-y-3 p-5" aria-labelledby="lk-fav">
                    <h2 id="lk-fav" class="text-sm font-semibold">Favicon</h2>
                    <p class="text-xs text-slate-500">The icon in the browser tab while you are in the platform area. Empty = the sign-in page's favicon. PNG, ICO or WebP.</p>
                    <div class="flex items-center gap-4"><span class="grid size-14 place-items-center rounded-lg border border-slate-200 bg-white"><img :src="look.favicon_url" alt="Current favicon" class="size-8 object-contain" data-testid="look-favicon" /></span>
                        <div class="flex flex-wrap gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/x-icon,image/webp,.ico" class="sr-only" data-testid="look-favicon-file" @change="upload('favicon', $event)" /></label><button v-if="look.favicon_custom" class="btn-ghost" data-testid="look-favicon-remove" @click="remove('favicon')">Use the sign-in favicon</button></div></div>
                </section>
            </div>
        </div>
    </SettingsShell>
</template>
