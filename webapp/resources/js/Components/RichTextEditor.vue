<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    AlignCenter, AlignJustify, AlignLeft, AlignRight, Bold, Code2, Eraser, Highlighter, Image as ImageIcon, Indent, Italic, Link2, List, ListOrdered, Minus, Outdent,
    Palette, Quote, Redo2, Strikethrough, Underline, Undo2, Unlink,
} from 'lucide-vue-next';
import { toast } from '@/lib/ui';

// The editor for the body of an e-mail (and signatures, templates and automations): what you see is what is sent. A standard word-processor toolbar:
// paragraph style, font and size, bold / italic / underline / strike-through, text colour and highlight, alignment, bullet and numbered lists,
// indent, quote, links, pictures (by address), a divider line, clear formatting, undo / redo, and the HTML itself for those who want it.
// v-model is the HTML. insertText(token) puts text (a merge field, say) at the cursor.
const props = defineProps({
    modelValue: { type: String, default: '' },
    minHeight: { type: String, default: 'min-h-48' },
    maxHeight: { type: String, default: 'max-h-96' },
    placeholder: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const el = ref(null);
const source = ref(false);               // the HTML view
const sourceText = ref('');
let saved = null;                         // the selection inside the editor, kept while a menu or a prompt has the focus

const BLOCKS = [['p', 'Normal text'], ['h1', 'Heading 1'], ['h2', 'Heading 2'], ['h3', 'Heading 3'], ['blockquote', 'Quote'], ['pre', 'Fixed width']];
const FONTS = [['', 'Font'], ['Arial', 'Arial'], ['Verdana', 'Verdana'], ['Trebuchet MS', 'Trebuchet'], ['Georgia', 'Georgia'], ['Times New Roman', 'Times'], ['Courier New', 'Courier']];
const SIZES = [['', 'Size'], ['1', 'Small'], ['3', 'Normal'], ['5', 'Large'], ['6', 'Larger'], ['7', 'Huge']];

// ---- keeping what the browser's editing commands produce safe: no scripts, no event handlers, no javascript: addresses
function clean(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script, style, iframe, object, embed, link, meta, form, base').forEach((n) => n.remove());
    doc.body.querySelectorAll('*').forEach((n) => {
        for (const a of [...n.attributes]) {
            const v = a.value.trim().toLowerCase();
            if (a.name.startsWith('on') || ((a.name === 'href' || a.name === 'src') && /^(javascript|data|vbscript):/.test(v) && !(a.name === 'src' && v.startsWith('data:image/')))) n.removeAttribute(a.name);
        }
    });
    return doc.body.innerHTML;
}

const emitHtml = () => emit('update:modelValue', el.value.innerHTML);
function remember() {
    const s = window.getSelection();
    if (s && s.rangeCount && el.value?.contains(s.anchorNode)) saved = s.getRangeAt(0).cloneRange();
}
function restore() {
    el.value?.focus();
    if (saved && !el.value?.contains(saved.startContainer)) saved = null;       // the text was replaced (a template was chosen): that place no longer exists
    if (!saved) return;
    const s = window.getSelection();
    s.removeAllRanges();
    s.addRange(saved);
}
function run(cmd, value = null) {
    restore();
    document.execCommand(cmd, false, value);
    emitHtml();
    remember();
}
const onBlock = (e) => { run('formatBlock', e.target.value); e.target.value = 'p'; };
const onFont = (e) => { e.target.value && run('fontName', e.target.value); e.target.value = ''; };
const onSize = (e) => { e.target.value && run('fontSize', e.target.value); e.target.value = ''; };

function link() {
    restore();
    const url = window.prompt('Link address (https://…, or mailto:you@example.com)', 'https://');
    if (!url) return;
    if (!/^(https?:\/\/|mailto:)\S+$/i.test(url.trim())) return toast('A link must start with https://, http:// or mailto:', 'error');
    const sel = window.getSelection();
    if (sel.isCollapsed) document.execCommand('insertHTML', false, `<a href="${url.trim().replace(/"/g, '&quot;')}">${url.trim().replace(/</g, '&lt;')}</a>`);
    else document.execCommand('createLink', false, url.trim());
    emitHtml();
}
function picture() {
    restore();
    const url = window.prompt('Picture address (https://…)', 'https://');
    if (!url) return;
    if (!/^https?:\/\/\S+$/i.test(url.trim())) return toast('A picture address must start with https:// or http://', 'error');
    document.execCommand('insertImage', false, url.trim());
    el.value.querySelectorAll('img:not([style])').forEach((i) => i.setAttribute('style', 'max-width:100%;height:auto'));       // never wider than the e-mail
    emitHtml();
}
function onPaste(e) {
    const html = e.clipboardData?.getData('text/html');
    if (!html) return;                                                // plain text pastes as it is
    e.preventDefault();
    document.execCommand('insertHTML', false, clean(html));
    emitHtml();
}

/** Text at the cursor (a merge field), or at the end when the editor never had the cursor. */
function insertText(text) {
    if (source.value) { sourceText.value += text; return; }
    restore();
    if (!saved) { const r = document.createRange(); r.selectNodeContents(el.value); r.collapse(false); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    if (!document.execCommand('insertText', false, text)) { el.value.append(document.createTextNode(text)); }      // the browser refused: the text still goes in, at the end
    emitHtml();
    remember();
}
const focus = () => el.value?.focus();
defineExpose({ insertText, focus });

async function toggleSource() {
    if (!source.value) { sourceText.value = el.value.innerHTML; source.value = true; return; }
    const html = clean(sourceText.value);
    source.value = false;
    await nextTick();
    el.value.innerHTML = html;
    emit('update:modelValue', html);
}

// outside changes (a template was chosen, a merge field was added to the text) show up in the editor without moving the cursor when it is the same text
watch(() => props.modelValue, (v) => { if (el.value && v !== el.value.innerHTML) { el.value.innerHTML = v ?? ''; saved = null; } });
onMounted(() => {
    el.value.innerHTML = props.modelValue ?? '';
    document.addEventListener('selectionchange', remember);
});
onBeforeUnmount(() => document.removeEventListener('selectionchange', remember));
const onFocus = () => document.execCommand('defaultParagraphSeparator', false, 'p');

const btn = 'btn-ghost !px-1.5 !py-1.5';
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white" data-testid="rich-text">
        <div class="flex flex-wrap items-center gap-0.5 border-b border-slate-100 bg-slate-50 px-1.5 py-1 text-sm" data-no-guard role="toolbar" aria-label="Formatting">
            <select class="input !w-32 !py-1 text-xs" aria-label="Paragraph style" :disabled="source" @change="onBlock"><option v-for="[v, l] in BLOCKS" :key="v" :value="v">{{ l }}</option></select>
            <select class="input !w-24 !py-1 text-xs" aria-label="Font" :disabled="source" @change="onFont"><option v-for="[v, l] in FONTS" :key="v" :value="v">{{ l }}</option></select>
            <select class="input !w-20 !py-1 text-xs" aria-label="Font size" :disabled="source" @change="onSize"><option v-for="[v, l] in SIZES" :key="v" :value="v">{{ l }}</option></select>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button type="button" :class="btn" title="Bold (Ctrl+B)" aria-label="Bold" :disabled="source" @mousedown.prevent @click="run('bold')"><Bold class="size-4" /></button>
            <button type="button" :class="btn" title="Italic (Ctrl+I)" aria-label="Italic" :disabled="source" @mousedown.prevent @click="run('italic')"><Italic class="size-4" /></button>
            <button type="button" :class="btn" title="Underline (Ctrl+U)" aria-label="Underline" :disabled="source" @mousedown.prevent @click="run('underline')"><Underline class="size-4" /></button>
            <button type="button" :class="btn" title="Strike-through" aria-label="Strike-through" :disabled="source" @mousedown.prevent @click="run('strikeThrough')"><Strikethrough class="size-4" /></button>
            <label :class="btn" class="relative cursor-pointer" title="Text colour"><Palette class="size-4" /><input type="color" class="absolute inset-0 size-full cursor-pointer opacity-0" aria-label="Text colour" :disabled="source" @input="run('foreColor', $event.target.value)" /></label>
            <label :class="btn" class="relative cursor-pointer" title="Highlight"><Highlighter class="size-4" /><input type="color" value="#fff3a3" class="absolute inset-0 size-full cursor-pointer opacity-0" aria-label="Highlight colour" :disabled="source" @input="run('hiliteColor', $event.target.value)" /></label>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button type="button" :class="btn" title="Align left" aria-label="Align left" :disabled="source" @mousedown.prevent @click="run('justifyLeft')"><AlignLeft class="size-4" /></button>
            <button type="button" :class="btn" title="Centre" aria-label="Align centre" :disabled="source" @mousedown.prevent @click="run('justifyCenter')"><AlignCenter class="size-4" /></button>
            <button type="button" :class="btn" title="Align right" aria-label="Align right" :disabled="source" @mousedown.prevent @click="run('justifyRight')"><AlignRight class="size-4" /></button>
            <button type="button" :class="btn" title="Justify" aria-label="Justify" :disabled="source" @mousedown.prevent @click="run('justifyFull')"><AlignJustify class="size-4" /></button>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button type="button" :class="btn" title="Bulleted list" aria-label="Bulleted list" :disabled="source" @mousedown.prevent @click="run('insertUnorderedList')"><List class="size-4" /></button>
            <button type="button" :class="btn" title="Numbered list" aria-label="Numbered list" :disabled="source" @mousedown.prevent @click="run('insertOrderedList')"><ListOrdered class="size-4" /></button>
            <button type="button" :class="btn" title="Decrease indent" aria-label="Decrease indent" :disabled="source" @mousedown.prevent @click="run('outdent')"><Outdent class="size-4" /></button>
            <button type="button" :class="btn" title="Increase indent" aria-label="Increase indent" :disabled="source" @mousedown.prevent @click="run('indent')"><Indent class="size-4" /></button>
            <button type="button" :class="btn" title="Quote" aria-label="Quote" :disabled="source" @mousedown.prevent @click="run('formatBlock', 'blockquote')"><Quote class="size-4" /></button>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button type="button" :class="btn" title="Add a link" aria-label="Add a link" :disabled="source" @mousedown.prevent @click="link"><Link2 class="size-4" /></button>
            <button type="button" :class="btn" title="Remove the link" aria-label="Remove the link" :disabled="source" @mousedown.prevent @click="run('unlink')"><Unlink class="size-4" /></button>
            <button type="button" :class="btn" title="Insert a picture" aria-label="Insert a picture" :disabled="source" @mousedown.prevent @click="picture"><ImageIcon class="size-4" /></button>
            <button type="button" :class="btn" title="Divider line" aria-label="Divider line" :disabled="source" @mousedown.prevent @click="run('insertHorizontalRule')"><Minus class="size-4" /></button>
            <button type="button" :class="btn" title="Clear formatting" aria-label="Clear formatting" :disabled="source" @mousedown.prevent @click="run('removeFormat')"><Eraser class="size-4" /></button>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button type="button" :class="btn" title="Undo" aria-label="Undo" :disabled="source" @mousedown.prevent @click="run('undo')"><Undo2 class="size-4" /></button>
            <button type="button" :class="btn" title="Redo" aria-label="Redo" :disabled="source" @mousedown.prevent @click="run('redo')"><Redo2 class="size-4" /></button>
            <button type="button" :class="[btn, source && '!bg-brand-50 !text-brand-700']" class="ml-auto" title="Edit the HTML" aria-label="Edit the HTML" :aria-pressed="source" data-testid="rich-source" @click="toggleSource"><Code2 class="size-4" /></button>
            <slot name="toolbar" />
        </div>
        <textarea v-if="source" v-model="sourceText" class="block w-full rounded-b-lg border-0 p-3 font-mono text-xs outline-none" :class="[minHeight, maxHeight]" spellcheck="false" aria-label="HTML" data-testid="rich-source-text" @input="emit('update:modelValue', clean(sourceText))" />
        <div v-show="!source" ref="el" contenteditable class="rich-body overflow-y-auto p-3 text-sm outline-none" :class="[minHeight, maxHeight]" :data-placeholder="placeholder" @input="emitHtml" @focus="onFocus" @paste="onPaste" @keyup="remember" @mouseup="remember" />
    </div>
</template>

<style scoped>
.rich-body:empty::before { content: attr(data-placeholder); color: #94a3b8; }
.rich-body :deep(h1) { font-size: 1.5rem; font-weight: 700; margin: 0.5rem 0; }
.rich-body :deep(h2) { font-size: 1.25rem; font-weight: 700; margin: 0.5rem 0; }
.rich-body :deep(h3) { font-size: 1.1rem; font-weight: 700; margin: 0.5rem 0; }
.rich-body :deep(ul) { list-style: disc; padding-left: 1.5rem; }
.rich-body :deep(ol) { list-style: decimal; padding-left: 1.5rem; }
.rich-body :deep(blockquote) { border-left: 3px solid #cbd5e1; margin: 0.5rem 0; padding-left: 0.75rem; color: #475569; }
.rich-body :deep(pre) { background: #f1f5f9; border-radius: 0.375rem; padding: 0.5rem; font-family: ui-monospace, monospace; font-size: 0.8rem; white-space: pre-wrap; }
.rich-body :deep(a) { color: #2563eb; text-decoration: underline; }
.rich-body :deep(hr) { border: 0; border-top: 1px solid #cbd5e1; margin: 0.75rem 0; }
.rich-body :deep(img) { max-width: 100%; height: auto; }
</style>
