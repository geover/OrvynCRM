<script setup>
import { ref } from 'vue';
import { FileText, Link2, Paperclip, X } from 'lucide-vue-next';

// What is attached to a note or a task, inside its form: the ones already saved (which can be removed), and the files and links added now. Nothing is
// sent from here; the form uploads `pending.files`, adds `pending.links` and deletes `pending.removed` once the note or task itself is saved.
const props = defineProps({
    existing: { type: Array, default: () => [] },                 // [{id, title, url, mime, size}]
    pending: { type: Object, required: true },                    // {files: File[], links: [{url, title}], removed: id[]}
});
const link = ref({ url: '', title: '' });
const MAX = 20 * 1024 * 1024;
const tooBig = ref('');

function pick(e) {
    tooBig.value = '';
    for (const f of e.target.files) {
        if (f.size > MAX) { tooBig.value = `“${f.name}” is over 20 MB.`; continue; }
        props.pending.files.push(f);
    }
    e.target.value = '';
}
function addLink() {
    const url = link.value.url.trim();
    if (!/^https?:\/\/\S+$/i.test(url)) return;
    props.pending.links.push({ url, title: link.value.title.trim() });
    link.value = { url: '', title: '' };
}
const kept = () => props.existing.filter((a) => !props.pending.removed.includes(a.id));
const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
</script>

<template>
    <div data-testid="attachment-picker">
        <label class="label">Attachments</label>
        <ul v-if="kept().length || pending.files.length || pending.links.length" class="mb-2 space-y-1.5">
            <li v-for="a in kept()" :key="`a${a.id}`" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 text-sm">
                <component :is="a.url ? Link2 : FileText" class="size-4 shrink-0 text-slate-400" /><span class="min-w-0 flex-1 truncate">{{ a.title }}</span>
                <button type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Remove ${a.title}`" @click="pending.removed.push(a.id)"><X class="size-4" /></button>
            </li>
            <li v-for="(f, i) in pending.files" :key="`f${i}`" class="flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-1.5 text-sm">
                <FileText class="size-4 shrink-0 text-brand-600" /><span class="min-w-0 flex-1 truncate">{{ f.name }}</span><span class="shrink-0 text-xs text-slate-400">{{ size(f.size) }}</span>
                <button type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Remove ${f.name}`" @click="pending.files.splice(i, 1)"><X class="size-4" /></button>
            </li>
            <li v-for="(l, i) in pending.links" :key="`l${i}`" class="flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-1.5 text-sm">
                <Link2 class="size-4 shrink-0 text-brand-600" /><span class="min-w-0 flex-1 truncate">{{ l.title || l.url }}</span>
                <button type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Remove ${l.title || l.url}`" @click="pending.links.splice(i, 1)"><X class="size-4" /></button>
            </li>
        </ul>
        <div class="flex flex-wrap items-start gap-2">
            <label class="btn-outline cursor-pointer"><Paperclip class="size-4" /> Attach files<input type="file" multiple class="hidden" data-testid="attach-file" @change="pick" /></label>
            <div class="flex min-w-0 flex-1 flex-wrap gap-2">
                <input v-model="link.url" type="url" class="input !w-56 flex-1" placeholder="Or paste a link: https://" aria-label="Link address" data-testid="attach-link-url" @keydown.enter.prevent="addLink" />
                <input v-model="link.title" class="input !w-36" placeholder="Title (optional)" aria-label="Link title" @keydown.enter.prevent="addLink" />
                <button type="button" class="btn-outline" :disabled="!link.url.trim()" data-testid="attach-link-add" @click="addLink"><Link2 class="size-4" /> Add link</button>
            </div>
        </div>
        <p v-if="tooBig" class="mt-1 text-xs text-red-600">{{ tooBig }}</p>
        <p class="mt-1 text-xs text-slate-400">Documents, images (PDF, Word, Excel, PowerPoint, PNG, JPG…) up to 20 MB each, or links. They are also kept on the contact's Attachments tab.</p>
    </div>
</template>
