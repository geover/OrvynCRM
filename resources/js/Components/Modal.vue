<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({ title: String, width: { type: String, default: 'max-w-lg' } });
const emit = defineEmits(['close']);
const panel = ref(null);
let opener = null;
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
// Escape closes; Tab stays inside the dialog; focus moves in on open and returns to the opener on close.
const onKey = (e) => {
    if (e.key === 'Escape') return emit('close');
    if (e.key !== 'Tab' || !panel.value) return;
    const items = [...panel.value.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0]; const last = items[items.length - 1];
    if (e.shiftKey && (document.activeElement === first || !panel.value.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
};
onMounted(() => {
    opener = document.activeElement;
    document.addEventListener('keydown', onKey);
    nextTick(() => { if (!panel.value?.contains(document.activeElement)) (panel.value?.querySelector('.overflow-y-auto input, .overflow-y-auto select, .overflow-y-auto textarea') ?? panel.value)?.focus(); });
});
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); if (opener && document.contains(opener)) opener.focus?.(); });
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 sm:items-center" @mousedown.self="emit('close')">
        <div ref="panel" :class="width" class="card flex max-h-[92vh] w-full flex-col outline-none" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h2 class="text-base font-semibold">{{ title }}</h2>
                <button class="btn-ghost !px-1.5" type="button" aria-label="Close dialog" @click="emit('close')"><X class="size-4" /></button>
            </div>
            <div class="overflow-y-auto p-5"><slot /></div>
            <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3"><slot name="footer" /></div>
        </div>
    </div>
</template>
