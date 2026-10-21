<script setup>
import { ref } from 'vue';
import { Lock, Paperclip, Send, X } from 'lucide-vue-next';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { toText } from '@/lib/richtext';
import { toast } from '@/lib/ui';

// Writing on a ticket: formatted text, up to five files (screenshots, a PDF, a log), and for the platform a switch that makes it a private note.
const props = defineProps({ busy: Boolean, allowNote: Boolean, placeholder: { type: String, default: 'Write your reply…' }, sendLabel: { type: String, default: 'Send reply' } });
const emit = defineEmits(['send']);

const body = ref('');
const files = ref([]);
const note = ref(false);
const picker = ref(null);
const MAX = 5; const MAX_MB = 8;
const ACCEPT = '.png,.jpg,.jpeg,.webp,.gif,.pdf,.txt,.log,.csv';

function pick(e) {
    for (const f of e.target.files) {
        if (files.value.length >= MAX) { toast(`You can attach up to ${MAX} files.`, 'error'); break; }
        if (f.size > MAX_MB * 1048576) { toast(`${f.name} is larger than ${MAX_MB} MB.`, 'error'); continue; }
        files.value.push(f);
    }
    e.target.value = '';
}
function submit() {
    if (!toText(body.value).trim() && !files.value.length) return toast('Write something, or attach a file.', 'error');
    emit('send', { body: body.value, files: files.value, note: note.value }, () => { body.value = ''; files.value = []; note.value = false; });
}
defineExpose({ focus: () => picker.value?.click });
</script>

<template>
    <div class="space-y-2" :class="note && 'rounded-xl border border-amber-200 bg-amber-50/60 p-2'">
        <RichTextEditor v-model="body" min-height="min-h-28" max-height="max-h-64" :placeholder="note ? 'A private note for the platform team. The company never sees it.' : placeholder" data-testid="ticket-body" />
        <ul v-if="files.length" class="flex flex-wrap gap-1.5"><li v-for="(f, i) in files" :key="f.name + i" class="badge gap-1">{{ f.name }}<button type="button" :aria-label="`Remove ${f.name}`" @click="files.splice(i, 1)"><X class="size-3" /></button></li></ul>
        <div class="flex flex-wrap items-center gap-2">
            <label class="btn-outline cursor-pointer !py-1.5"><Paperclip class="size-4" /> Attach<input ref="picker" type="file" multiple :accept="ACCEPT" class="hidden" data-testid="ticket-files" @change="pick" /></label>
            <label v-if="allowNote" class="flex cursor-pointer items-center gap-1.5 text-sm text-amber-800"><input v-model="note" type="checkbox" class="rounded" data-testid="ticket-note" /><Lock class="size-3.5" /> Private note</label>
            <button type="button" class="btn-primary ml-auto" :disabled="busy" data-testid="ticket-send" @click="submit"><Send class="size-4" /> {{ note ? 'Add the note' : sendLabel }}</button>
        </div>
    </div>
</template>
