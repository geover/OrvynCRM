<script setup>
// An on/off switch (role="switch"). Attributes such as data-testid, data-service and title land on the button itself.
// `dimmed` shows "on, but held off by something bigger" (for example a data object switch while the whole company is off).
defineProps({ on: { type: Boolean, required: true }, label: { type: String, required: true }, small: Boolean, dimmed: Boolean, disabled: Boolean });
defineEmits(['toggle']);
</script>

<template>
    <button type="button" role="switch" :aria-checked="on" :aria-label="label" class="relative inline-flex shrink-0 items-center rounded-full align-middle transition disabled:cursor-not-allowed disabled:opacity-40"
        :disabled="disabled" :class="[small ? 'h-5 w-9' : 'h-6 w-11', on ? (dimmed ? 'bg-emerald-300' : 'bg-emerald-500') : 'bg-slate-300']" @click="$emit('toggle')">
        <span class="inline-block rounded-full bg-white shadow transition" :class="[small ? 'size-4' : 'size-5', on ? (small ? 'translate-x-4' : 'translate-x-5') : 'translate-x-0.5']" />
    </button>
</template>
