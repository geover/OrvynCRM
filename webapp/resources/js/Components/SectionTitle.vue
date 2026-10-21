<script setup>
// The heading of a settings section: a small flat icon tile, the title, and a line saying what the section is for.
defineOptions({ inheritAttrs: false });
defineProps({ icon: { type: Object, required: true }, title: { type: String, required: true }, hint: { type: String, default: '' }, tone: { type: String, default: 'bg-indigo-100 text-indigo-700' }, id: { type: String, default: undefined } });
</script>

<template>
    <div class="flex items-center gap-3" v-bind="$attrs">
        <span class="grid size-9 shrink-0 place-items-center rounded-xl" :class="tone" aria-hidden="true"><component :is="icon" class="size-[18px]" /></span>
        <div class="min-w-0"><h2 :id="id" class="text-sm font-semibold leading-tight">{{ title }}</h2><p v-if="hint" class="text-xs text-slate-400">{{ hint }}</p></div>
    </div>
</template>
