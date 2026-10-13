<script setup>
import { computed, ref } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { DARK_STYLES } from '@/lib/theme';

// The look of the dark theme: a collapsible row of colour swatches, shown under a Theme switch whenever dark can apply.
// Closed it shows only the current style; v-model is the style id ('navy', 'graphite', ...). Each area (company app,
// platform) keeps its own choice.
const props = defineProps({ modelValue: { type: String, required: true }, startOpen: Boolean });
defineEmits(['update:modelValue']);

const open = ref(props.startOpen);
const current = computed(() => DARK_STYLES.find((s) => s.id === props.modelValue) ?? DARK_STYLES[0]);
</script>

<template>
    <div data-testid="dark-styles">
        <button type="button" class="flex w-full items-center justify-between gap-2 rounded-md py-0.5 text-xs text-slate-500 hover:text-slate-700" :aria-expanded="open" aria-controls="dark-style-options" data-testid="dark-styles-toggle" @click="open = !open">
            <span>Dark style</span>
            <span class="flex items-center gap-1.5 font-medium text-slate-700">
                <span class="block size-3.5 rounded-full ring-1 ring-black/20" :style="{ background: current.surface, boxShadow: `inset 0 0 0 3px ${current.page}` }" />{{ current.label }}
                <ChevronDown class="size-3.5 transition" :class="open && 'rotate-180'" />
            </span>
        </button>
        <div v-show="open" id="dark-style-options" class="mt-1.5 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Dark theme style">
            <button v-for="s in DARK_STYLES" :key="s.id" type="button" role="radio" :aria-checked="modelValue === s.id" :aria-label="`${s.label} dark style`" :title="`${s.label}: ${s.hint}`" :data-dark-style="s.id"
                class="group flex flex-col items-center gap-1 rounded-lg p-1 text-[10px] text-slate-500 transition hover:bg-slate-100" :class="modelValue === s.id && 'bg-slate-100 font-medium text-slate-700'" @click="$emit('update:modelValue', s.id)">
                <span class="relative block size-8 overflow-hidden rounded-full ring-1 ring-black/20" :class="modelValue === s.id && '!ring-2 !ring-brand-500'" :style="{ background: s.page }">
                    <span class="absolute inset-x-1 bottom-1 top-3 rounded-sm" :style="{ background: s.surface, boxShadow: `0 0 0 1px ${s.line}` }" />
                </span>
                {{ s.label }}
            </button>
        </div>
    </div>
</template>
