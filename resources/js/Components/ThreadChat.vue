<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ArrowDown, Paperclip, Send, X } from 'lucide-vue-next';
import { fmtDate, fmtRelative, toast } from '@/lib/ui';

// A conversation as chat bubbles: who wrote each message (name and role), when, any images, and a reply box under it.
// `mine` is the side this viewer belongs to ('tenant' | 'platform'): its messages sit on the right.
const props = defineProps({
    messages: { type: Array, required: true },
    mine: { type: String, required: true },
    canReply: { type: Boolean, default: true },
    sending: Boolean,
    placeholder: { type: String, default: 'Write a reply…' },
    blockedText: { type: String, default: '' },
    density: { type: String, default: 'comfortable' },       // 'comfortable' (bubbles) | 'compact' (a dense log)
});
const emit = defineEmits(['send']);

const MAX_FILES = 4;
const MAX_MB = 5;
const body = ref('');
const files = ref([]);                          // [{ file, url }]
const scroller = ref(null);
const picker = ref(null);
const zoomed = ref(null);                       // the image shown full size
const ROLE = { tenant: 'Company admin', platform: 'Platform admin' };

const compact = computed(() => props.density === 'compact');
const unseen = ref(0);                          // messages that arrived while the reader was scrolled up
const nearBottom = () => !scroller.value || scroller.value.scrollHeight - scroller.value.scrollTop - scroller.value.clientHeight < 80;
const toBottom = () => nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; unseen.value = 0; });
const onScroll = () => { if (nearBottom()) unseen.value = 0; };
const escClose = (e) => { if (e.key === 'Escape') zoomed.value = null; };
onMounted(() => { toBottom(); document.addEventListener('keydown', escClose); });
// New messages follow the reader down only when they were already at the bottom or wrote the message themselves;
// otherwise they keep their place and a "new messages" button appears.
watch(() => props.messages.length, (now, before) => {
    const added = now - before;
    if (added <= 0) return;
    if (props.messages[now - 1]?.from === props.mine || nearBottom()) toBottom();
    else unseen.value += added;
});

function addFiles(list) {
    for (const file of Array.from(list)) {
        if (!/^image\/(png|jpe?g|webp|gif)$/.test(file.type)) { toast(`${file.name || 'That file'} is not a PNG, JPG, WebP or GIF image.`, 'error'); continue; }
        if (file.size > MAX_MB * 1024 * 1024) { toast(`${file.name || 'That image'} is larger than ${MAX_MB} MB.`, 'error'); continue; }
        if (files.value.length >= MAX_FILES) { toast(`At most ${MAX_FILES} images per message.`, 'error'); break; }
        files.value.push({ file, url: URL.createObjectURL(file) });
    }
}
function removeFile(i) { URL.revokeObjectURL(files.value[i].url); files.value.splice(i, 1); }
const clearFiles = () => { files.value.forEach((f) => URL.revokeObjectURL(f.url)); files.value = []; };
onBeforeUnmount(() => { clearFiles(); document.removeEventListener('keydown', escClose); });

function onPaste(e) {
    const images = Array.from(e.clipboardData?.files ?? []).filter((f) => f.type.startsWith('image/'));
    if (images.length) { e.preventDefault(); addFiles(images); }
}

const canSend = computed(() => (body.value.trim() || files.value.length) && !props.sending);
function send() {
    if (!canSend.value) return;
    emit('send', body.value.trim(), files.value.map((f) => f.file), () => { body.value = ''; clearFiles(); });
}
const onKey = (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); } if (e.key === 'Escape') zoomed.value = null; };
const size = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" @keydown.esc="zoomed = null">
        <div class="relative flex min-h-0 flex-1 flex-col">
            <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto" :class="compact ? 'divide-y divide-slate-100 bg-white py-1' : 'space-y-4 px-4 py-4'" :data-density="density" data-testid="thread-messages" role="log" aria-label="Conversation" aria-live="polite" @scroll.passive="onScroll">
                <template v-if="compact">
                    <div v-for="m in messages" :key="m.id" class="px-4 py-1.5 text-[13px] leading-snug" :class="m.from === mine ? 'text-right' : ''" :data-from="m.from">
                        <div class="flex flex-wrap items-baseline gap-x-2" :class="m.from === mine ? 'justify-end' : ''">
                            <span class="font-semibold text-slate-800" data-testid="message-author">{{ m.name }}</span>
                            <span class="rounded px-1 text-[9px] font-semibold uppercase tracking-wide" :class="m.from === 'platform' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-600'">{{ ROLE[m.from] }}</span>
                            <span class="text-[11px] text-slate-400" :title="fmtDate(m.created_at, true)">{{ fmtRelative(m.created_at) }}</span>
                        </div>
                        <p v-if="m.body" class="whitespace-pre-line text-slate-700" :class="m.from === mine ? 'ml-auto max-w-[85%]' : 'max-w-[85%]'">{{ m.body }}</p>
                        <div v-if="m.attachments?.length" class="mt-1 flex flex-wrap gap-1.5" :class="m.from === mine ? 'justify-end' : ''" data-testid="message-images">
                            <button v-for="a in m.attachments" :key="a.id" type="button" class="overflow-hidden rounded ring-1 ring-black/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" :title="`${a.name} (${size(a.size)})`" :aria-label="`Open image ${a.name}`" data-testid="message-image" @click="zoomed = a">
                                <img :src="a.url" :alt="a.name" loading="lazy" class="max-h-16 max-w-[6rem] object-cover" />
                            </button>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div v-for="m in messages" :key="m.id" class="flex" :class="m.from === mine ? 'justify-end' : 'justify-start'" :data-from="m.from">
                        <div class="max-w-[85%] sm:max-w-[75%]">
                            <div class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs" :class="m.from === mine ? 'justify-end' : ''">
                                <span class="font-medium text-slate-700" data-testid="message-author">{{ m.name }}</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide" :class="m.from === 'platform' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-600'">{{ ROLE[m.from] }}</span>
                                <span class="text-slate-400" :title="fmtDate(m.created_at, true)">{{ fmtRelative(m.created_at) }}</span>
                            </div>
                            <div class="rounded-2xl px-4 py-2.5 text-sm leading-relaxed shadow-sm" :class="m.from === mine ? 'rounded-br-md bg-brand-600 text-white' : 'rounded-bl-md border border-slate-200 bg-white text-slate-800'">
                                <p v-if="m.body" class="whitespace-pre-line">{{ m.body }}</p>
                                <div v-if="m.attachments?.length" class="flex flex-wrap gap-2" :class="m.body && 'mt-2'" data-testid="message-images">
                                    <button v-for="a in m.attachments" :key="a.id" type="button" class="overflow-hidden rounded-lg ring-1 ring-black/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" :title="`${a.name} (${size(a.size)})`" :aria-label="`Open image ${a.name}`" data-testid="message-image" @click="zoomed = a">
                                        <img :src="a.url" :alt="a.name" loading="lazy" class="max-h-48 max-w-[14rem] object-cover" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <button v-if="unseen" type="button" class="absolute bottom-3 left-1/2 inline-flex -translate-x-1/2 items-center gap-1 rounded-full bg-brand-600 px-3 py-1.5 text-xs font-medium text-white shadow-lg" data-testid="new-messages" @click="toBottom"><ArrowDown class="size-3.5" /> {{ unseen }} new {{ unseen === 1 ? 'message' : 'messages' }}</button>
        </div>

        <form v-if="canReply" class="border-t border-slate-200 bg-white p-3" data-testid="reply-form" @submit.prevent="send">
            <div v-if="files.length" class="mb-2 flex flex-wrap gap-2" data-testid="attachment-previews">
                <div v-for="(f, i) in files" :key="f.url" class="group relative">
                    <img :src="f.url" :alt="f.file.name" class="size-16 rounded-lg object-cover ring-1 ring-slate-200" />
                    <button type="button" class="absolute -right-1.5 -top-1.5 grid size-5 place-items-center rounded-full bg-slate-800 text-white shadow" :aria-label="`Remove ${f.file.name}`" data-testid="remove-attachment" @click="removeFile(i)"><X class="size-3" /></button>
                </div>
            </div>
            <label class="sr-only" for="thread-reply">Reply</label>
            <div class="flex items-end gap-2">
                <input ref="picker" type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="hidden" data-testid="attach-input" @change="addFiles($event.target.files); $event.target.value = ''" />
                <button type="button" class="btn-ghost shrink-0 !px-2.5" title="Attach images" aria-label="Attach images" data-testid="attach-button" :disabled="files.length >= MAX_FILES" @click="picker.click()"><Paperclip class="size-4" /></button>
                <textarea id="thread-reply" v-model="body" rows="2" maxlength="5000" class="input min-h-[3rem] flex-1 resize-y" :placeholder="placeholder" @keydown="onKey" @paste="onPaste" />
                <button class="btn-primary shrink-0" :disabled="!canSend" data-testid="send-reply"><Send class="size-4" /> Send</button>
            </div>
            <p class="mt-1 text-[11px] text-slate-400">Ctrl+Enter to send · attach or paste up to {{ MAX_FILES }} images (PNG, JPG, WebP, GIF, {{ MAX_MB }} MB each)</p>
        </form>
        <p v-else-if="blockedText" class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ blockedText }}</p>

        <div v-if="zoomed" class="fixed inset-0 z-[70] grid place-items-center bg-slate-900/80 p-4" role="dialog" aria-modal="true" :aria-label="zoomed.name" data-testid="image-viewer" tabindex="-1" @click.self="zoomed = null" @keydown.esc="zoomed = null">
            <button type="button" class="absolute right-4 top-4 grid size-9 place-items-center rounded-full bg-white/90 text-slate-800 shadow" aria-label="Close image" @click="zoomed = null"><X class="size-5" /></button>
            <img :src="zoomed.url" :alt="zoomed.name" class="max-h-full max-w-full rounded-lg object-contain shadow-2xl" />
        </div>
    </div>
</template>
