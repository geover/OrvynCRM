<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { loadMergeFields } from '@/lib/mergeFields';

// The "Merge fields" button and its list, as in the e-mail form: pick one and it is handed to the form, which puts it in the message.
// The list is drawn on the page itself (not inside the pop-up that holds the button), so a short pop-up never cuts it off; it opens below the
// button, or above it when there is no room below.
const emit = defineEmits(['pick']);
const groups = ref([]);
const open = ref(false);
const button = ref(null);
const panel = ref(null);
const style = ref({});
const WIDTH = 240;
const MAX_HEIGHT = 288;

onMounted(async () => {
    groups.value = await loadMergeFields();
    document.addEventListener('mousedown', outside);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', outside);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
});

function close() { open.value = false; }
function outside(e) { if (open.value && !button.value?.contains(e.target) && !panel.value?.contains(e.target)) close(); }

// Put the list next to the button. Called when it opens and whenever the page or the pop-up scrolls or is resized, so it stays attached
// (closing on scroll would shut it as the pop-up scrolls to keep focus, before a click could land).
function place() {
    if (!open.value || !button.value) return;
    const r = button.value.getBoundingClientRect();
    const room = window.innerHeight - r.bottom - 12;
    const below = room >= 180 || room >= r.top;                    // below when it fits (or when above would be worse)
    style.value = {
        width: `${WIDTH}px`,
        left: `${Math.max(8, Math.min(r.right - WIDTH, window.innerWidth - WIDTH - 8))}px`,
        maxHeight: `${Math.max(120, Math.min(MAX_HEIGHT, below ? room : r.top - 12))}px`,
        ...(below ? { top: `${r.bottom + 4}px` } : { bottom: `${window.innerHeight - r.top + 4}px` }),
    };
}
function toggle() {
    if (open.value) return close();
    open.value = true;
    place();
}
const pick = (token) => { close(); emit('pick', token); };
</script>

<template>
    <button ref="button" type="button" class="btn-outline !px-2 !py-1 text-sm" aria-haspopup="true" :aria-expanded="open" data-testid="merge-fields" @click="toggle" @keydown.esc="close">Merge fields</button>
    <Teleport to="body">
        <div v-if="open" ref="panel" class="card fixed z-[60] overflow-y-auto p-2 shadow-lg" :style="style" role="menu" data-testid="merge-fields-list" @mousedown.prevent @keydown.esc="close">
            <template v-for="g in groups" :key="g.group">
                <div class="px-2 pb-1 pt-2 text-xs font-semibold uppercase text-slate-400">{{ g.group }}</div>
                <button v-for="f in g.fields" :key="f.token" type="button" role="menuitem" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-slate-50" @click="pick(f.token)">{{ f.label }}</button>
            </template>
            <p v-if="!groups.length" class="px-2 py-2 text-xs text-slate-400">No merge fields available.</p>
        </div>
    </Teleport>
</template>
