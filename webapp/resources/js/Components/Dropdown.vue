<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

// A button + floating panel. The trigger slot receives {open, toggle}; the default slot receives {close}.
defineProps({ align: { type: String, default: 'right' }, width: { type: String, default: 'w-56' } });
const open = ref(false);
const root = ref(null);
const onDoc = (e) => { if (root.value && !root.value.contains(e.target)) open.value = false; };
const onKey = (e) => { if (e.key === 'Escape') open.value = false; };
onMounted(() => { document.addEventListener('mousedown', onDoc); document.addEventListener('keydown', onKey); });
onBeforeUnmount(() => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); });
</script>

<template>
    <div ref="root" class="relative">
        <slot name="trigger" :open="open" :toggle="() => (open = !open)" />
        <div v-if="open" :class="[align === 'right' ? 'right-0' : 'left-0', width]" class="card absolute top-full z-30 mt-1 p-2 shadow-lg"><slot :close="() => (open = false)" /></div>
    </div>
</template>
