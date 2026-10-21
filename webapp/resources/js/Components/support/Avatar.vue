<script setup>
import { computed } from 'vue';

// A round badge with the person's initials, in a colour that stays the same for that name: it makes lists of people easy to tell apart at a glance.
const props = defineProps({ name: { type: String, default: '' }, size: { type: String, default: 'md' }, ring: Boolean });
const TINTS = ['bg-rose-100 text-rose-700', 'bg-amber-100 text-amber-800', 'bg-emerald-100 text-emerald-700', 'bg-sky-100 text-sky-700', 'bg-violet-100 text-violet-700', 'bg-teal-100 text-teal-700', 'bg-orange-100 text-orange-700', 'bg-indigo-100 text-indigo-700'];
const initials = computed(() => (props.name || '?').trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase());
const tint = computed(() => TINTS[[...(props.name || '?')].reduce((n, c) => n + c.charCodeAt(0), 0) % TINTS.length]);
</script>

<template>
    <span class="grid shrink-0 place-items-center rounded-full font-semibold transition" :class="[tint, size === 'sm' ? 'size-6 text-[10px]' : size === 'lg' ? 'size-11 text-sm' : 'size-9 text-xs', ring && 'ring-2 ring-brand-500 ring-offset-2']" aria-hidden="true">{{ initials }}</span>
</template>
