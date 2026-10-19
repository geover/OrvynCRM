<script setup>
import { onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { isLightColor } from '@/lib/brand';
import { confirmDialog, run, toast } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

const page = usePage();
const color = ref('#4f46e5');
const customSidebar = ref(false);
const sidebarColor = ref('#1e1b4b');
const logo = ref(null);
const logoIsDefault = ref(true);
const favicon = ref(null);
const faviconIsDefault = ref(true);
const SIDEBAR_PRESETS = ['#1e1b4b', '#0f172a', '#134e4a', '#7f1d1d', '#1e3a8a', '#f8fafc', '#e0e7ff', '#fef3c7'];
const PRESETS = ['#4f46e5', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#c026d3', '#475569'];

onMounted(async () => { const s = (await api.get('/settings')).data; color.value = s.color; customSidebar.value = !!s.sidebar_color; sidebarColor.value = s.sidebar_color || '#1e1b4b'; logo.value = s.logo_url; logoIsDefault.value = s.logo_is_default; favicon.value = s.favicon_url; faviconIsDefault.value = s.favicon_is_default; });

async function saveColor() {
    if (await run(() => api.put('/settings', { color: color.value, sidebar_color: customSidebar.value ? sidebarColor.value : null }), 'Theme colour updated')) router.reload({ only: ['app'] });
}
async function upload(e) {
    const file = e.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('logo', file);
    const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/api/v1/settings/logo', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
    const d = await r.json();
    if (!r.ok) return toast(d.errors?.logo?.[0] ?? d.message ?? 'Upload failed', 'error');
    logo.value = d.data.logo_url; logoIsDefault.value = d.data.logo_is_default; toast('Logo updated'); router.reload({ only: ['app'] });
    e.target.value = '';
}
async function uploadFavicon(e) {
    const file = e.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('favicon', file);
    const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/api/v1/settings/favicon', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
    const d = await r.json();
    if (!r.ok) return toast(d.errors?.favicon?.[0] ?? d.message ?? 'Upload failed', 'error');
    favicon.value = d.data.favicon_url; faviconIsDefault.value = d.data.favicon_is_default; toast('Favicon updated'); router.reload({ only: ['app'] });
    e.target.value = '';
}
async function removeFavicon() {
    if (!(await confirmDialog({ title: 'Go back to the platform favicon?', confirmLabel: 'Use platform favicon' }))) return;
    const d = await run(() => api.delete('/settings/favicon'), 'Favicon removed');
    if (d) { favicon.value = d.data.favicon_url; faviconIsDefault.value = d.data.favicon_is_default; }
    router.reload({ only: ['app'] });
}
async function removeLogo() {
    if (!(await confirmDialog({ title: 'Go back to the platform logo?', confirmLabel: 'Use platform logo', danger: true }))) return;
    const d = await run(() => api.delete('/settings/logo'), 'Logo removed');
    if (d) { logo.value = d.data.logo_url; logoIsDefault.value = d.data.logo_is_default; }
    router.reload({ only: ['app'] });
}
</script>

<template>
    <AdminPage title="Look & feel" subtitle="Customise your brand logo and theme colour.">
        <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Theme colour</h2>
                <div class="flex items-center gap-3"><input v-model="color" type="color" class="h-12 w-16 cursor-pointer rounded border border-slate-200" /><input v-model="color" class="input !w-32 font-mono" maxlength="7" /></div>
                <div class="flex flex-wrap gap-2"><button v-for="c in PRESETS" :key="c" :style="{ background: c }" class="size-8 rounded-full ring-offset-2 hover:ring-2" :class="color === c && 'ring-2 ring-slate-400'" @click="color = c" /></div>
                <div class="rounded-lg p-4 text-sm text-white" :style="{ background: color }">Preview: buttons and highlights use this colour{{ customSidebar ? '' : ', and so does the sidebar' }}.</div>
                <label class="flex cursor-pointer items-start gap-2 border-t border-slate-100 pt-4 text-sm"><input v-model="customSidebar" type="checkbox" class="mt-0.5 rounded" data-testid="custom-sidebar" /><span><span class="font-medium">Customise the sidebar colour</span><span class="block text-xs text-slate-500">Leave unticked and the sidebar follows the theme colour.</span></span></label>
                <div v-if="customSidebar" class="space-y-3" data-testid="sidebar-colour-controls">
                    <div class="flex items-center gap-3"><input v-model="sidebarColor" type="color" class="h-12 w-16 cursor-pointer rounded border border-slate-200" aria-label="Sidebar colour" /><input v-model="sidebarColor" class="input !w-32 font-mono" maxlength="7" aria-label="Sidebar colour code" /></div>
                    <div class="flex flex-wrap gap-2"><button v-for="c in SIDEBAR_PRESETS" :key="c" type="button" :style="{ background: c }" class="size-8 rounded-full ring-1 ring-slate-300 ring-offset-2 hover:ring-2" :class="sidebarColor === c && '!ring-2 !ring-slate-500'" :aria-label="`Use ${c}`" @click="sidebarColor = c" /></div>
                    <div class="rounded-lg p-4 text-sm font-medium" :style="{ background: sidebarColor, color: isLightColor(sidebarColor) ? '#0f172a' : '#fff' }">Sidebar preview</div>
                    <p class="text-xs text-slate-400">Light colours switch the sidebar text to dark. A logo designed for dark backgrounds reads best on a dark sidebar.</p>
                </div>
                <button class="btn-primary" @click="saveColor">Save colour</button>
            </section>
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Company logo</h2>
                <div class="grid h-28 place-items-center rounded-lg bg-slate-800"><img v-if="logo" :src="logo" alt="Logo" class="max-h-20 max-w-[80%] object-contain" /><span v-else class="text-sm text-slate-400">Loading…</span></div>
                <p class="text-xs text-slate-400">{{ logoIsDefault ? 'Showing the platform logo. Upload your own to replace it for your company.' : 'Your company logo.' }} PNG, JPG or WebP, up to 2 MB. Shown at the top of the sidebar.</p>
                <div class="flex gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="upload" /></label>
                    <button v-if="!logoIsDefault" class="btn-ghost text-red-600" @click="removeLogo">Use platform logo</button></div>
            </section>
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Favicon</h2>
                <div class="flex h-28 items-center justify-center gap-6 rounded-lg bg-slate-100"><img v-if="favicon" :src="favicon" alt="Favicon" class="size-16 object-contain" /><img v-if="favicon" :src="favicon" alt="" class="size-8 object-contain" /><img v-if="favicon" :src="favicon" alt="" class="size-4 object-contain" /></div>
                <p class="text-xs text-slate-400">{{ faviconIsDefault ? 'Showing the platform favicon. Upload your own to replace it for your company.' : 'Your company favicon.' }} The small icon in the browser tab. Square PNG, ICO or WebP, up to 512 KB (180x180 or larger is best).</p>
                <div class="flex gap-2"><label class="btn-outline cursor-pointer">Choose file<input type="file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp,.ico" class="hidden" data-testid="company-favicon" @change="uploadFavicon" /></label>
                    <button v-if="!faviconIsDefault" class="btn-ghost text-red-600" @click="removeFavicon">Use platform favicon</button></div>
            </section>
        </div>
    </AdminPage>
</template>
