<script setup>
// One key number: a small label, the number (with an optional dimmer suffix such as "/ 12"), and a hint underneath. With
// `action` the tile is a button that opens something, and says so. The default slot adds extra content (a meter, say).
defineProps({ label: { type: String, required: true }, value: { type: [String, Number], required: true }, suffix: { type: String, default: '' }, hint: { type: String, default: '' }, danger: Boolean, action: { type: String, default: '' } });
defineEmits(['click']);
</script>

<template>
    <component :is="action ? 'button' : 'div'" :type="action ? 'button' : undefined" class="card block w-full p-4 text-left" :class="action && 'transition hover:border-brand-500'" :aria-haspopup="action ? 'dialog' : undefined" :title="action || undefined" @click="action && $emit('click')">
        <div class="text-xs uppercase tracking-wide text-slate-500">{{ label }}</div>
        <div class="mt-1 text-2xl font-semibold tabular-nums" :class="danger && 'text-red-600'">{{ value }}<span v-if="suffix" class="text-base font-normal text-slate-400">{{ suffix }}</span></div>
        <div v-if="hint" class="text-xs text-slate-400">{{ hint }}</div>
        <slot />
        <div v-if="action" class="mt-1 text-xs text-brand-700">{{ action }}</div>
    </component>
</template>
