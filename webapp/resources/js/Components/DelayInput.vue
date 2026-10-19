<script setup>
import { computed } from 'vue';

// A wait, typed in the unit that makes sense (minutes, hours, days) and stored as minutes. 4320 shows as "3 days".
const model = defineModel({ type: Number, default: 0 });
defineProps({ id: { type: String, default: undefined } });
const UNITS = [['minutes', 1], ['hours', 60], ['days', 1440]];
// the largest unit that divides the value evenly, so 90 stays "90 minutes" and 2880 becomes "2 days"
const unit = computed(() => [...UNITS].reverse().find(([, size]) => model.value > 0 && model.value % size === 0)?.[0] ?? 'minutes');
const size = (u) => UNITS.find(([n]) => n === u)[1];
const amount = computed(() => (model.value ? model.value / size(unit.value) : 0));
const setAmount = (e) => { model.value = Math.max(0, Math.round(Number(e.target.value) || 0)) * size(unit.value); };
const setUnit = (e) => { model.value = amount.value * size(e.target.value); };
</script>

<template>
    <div class="flex gap-2">
        <input :id="id" :value="amount" type="number" min="0" class="input w-24" aria-label="Amount" data-testid="delay-amount" @input="setAmount" />
        <select :value="unit" class="input w-32" aria-label="Unit" data-testid="delay-unit" @change="setUnit"><option v-for="[u] in UNITS" :key="u" :value="u">{{ u }}</option></select>
    </div>
</template>
