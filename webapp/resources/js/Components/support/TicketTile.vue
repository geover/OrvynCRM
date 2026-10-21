<script setup>
// One key number with a coloured icon, for the support pages: what it counts, the number, a hint, and (when clickable) whether it is the filter on now.
defineProps({
    icon: { type: Object, required: true },
    tone: { type: String, default: 'bg-slate-100 text-slate-500' },        // the icon's gradient
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    hint: { type: String, default: '' },
    alert: Boolean,                                                         // something is waiting on the reader: the number is red and the icon pulses
    active: Boolean,
    clickable: Boolean,
    wrap: Boolean,                                                          // let the hint run over several lines
    valueTestid: { type: String, default: undefined },
});
defineEmits(['click']);
</script>

<template>
    <component :is="clickable ? 'button' : 'div'" :type="clickable ? 'button' : undefined" class="card group flex w-full items-center gap-3.5 p-4 text-left transition duration-200" :class="[clickable && 'hover:-translate-y-0.5 hover:shadow-md', active && 'tile-on']" :aria-pressed="clickable ? active : undefined" @click="$emit('click')">
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl transition duration-200 group-hover:scale-105" :class="[tone, alert && 'msg-pop']"><component :is="icon" class="size-5" /></span>
        <span class="min-w-0">
            <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">{{ label }}</span>
            <span class="block text-2xl font-semibold tabular-nums leading-tight" :class="alert && 'text-red-600'" :data-testid="valueTestid">{{ value }}</span>
            <span v-if="hint" class="block text-xs text-slate-400" :class="!wrap && 'truncate'">{{ hint }}</span>
            <slot />
        </span>
    </component>
</template>
