<script setup>
import { Rows2, Rows3 } from 'lucide-vue-next';
import { messageDensity } from '@/lib/density';

// Comfortable (roomy bubbles) or compact (a dense log that fits more on screen).
const OPTIONS = [['comfortable', 'Comfortable', Rows2], ['compact', 'Compact', Rows3]];
</script>

<template>
    <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-xs" role="radiogroup" aria-label="Message view" data-testid="density-toggle">
        <button v-for="[value, label, icon] in OPTIONS" :key="value" type="button" role="radio" :aria-checked="messageDensity === value" :data-density="value"
            :class="messageDensity === value ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'" class="inline-flex items-center gap-1 rounded-md px-2 py-1 font-medium" @click="messageDensity = value">
            <component :is="icon" class="size-3.5" /> {{ label }}
        </button>
    </div>
</template>
