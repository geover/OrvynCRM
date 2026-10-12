<script setup>
// One "label ... value" line inside a SettingsCard. The default slot is the value.
defineProps({ label: { type: String, required: true } });
</script>

<template>
    <div class="flex items-center justify-between gap-3"><dt class="text-slate-500">{{ label }}</dt><dd class="min-w-0 truncate font-medium tabular-nums"><slot /></dd></div>
</template>
