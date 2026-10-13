<script setup>
import { computed } from 'vue';

// Renders one data-object field (as defined in data_object_fields) bound with v-model.
const props = defineProps({ field: Object, modelValue: null, error: String, compact: Boolean });
const emit = defineEmits(['update:modelValue']);

const v = computed({ get: () => props.modelValue, set: (x) => emit('update:modelValue', x) });
const choices = computed(() => props.field.options?.choices ?? []);
const htmlType = computed(() => ({ number: 'number', date: 'date', datetime: 'datetime-local', email: 'email', url: 'url' })[props.field.type] ?? 'text');
const multi = computed({
    get: () => (Array.isArray(props.modelValue) ? props.modelValue : []),
    set: (x) => emit('update:modelValue', x),
});
</script>

<template>
    <div>
        <label v-if="!compact" class="label">{{ field.label }}<span v-if="field.is_required" class="text-red-500"> *</span></label>
        <textarea v-if="field.type === 'textarea'" v-model="v" rows="3" class="input" :placeholder="field.options?.placeholder" />
        <select v-else-if="field.type === 'select'" v-model="v" class="input"><option value="" />
            <option v-for="c in choices" :key="c" :value="c">{{ c }}</option></select>
        <div v-else-if="field.type === 'multiselect'" class="flex flex-wrap gap-x-4 gap-y-1 rounded-lg border border-slate-200 bg-white p-2 text-sm">
            <label v-for="c in choices" :key="c" class="flex items-center gap-1.5"><input v-model="multi" type="checkbox" :value="c" class="rounded" /> {{ c }}</label>
        </div>
        <label v-else-if="field.type === 'checkbox'" class="flex items-center gap-2 text-sm"><input v-model="v" type="checkbox" class="rounded" /> {{ compact ? field.label : 'Yes' }}</label>
        <input v-else v-model="v" :type="htmlType" class="input" :placeholder="field.options?.placeholder" :step="field.type === 'number' ? 'any' : undefined" />
        <p v-if="field.options?.helper && !compact" class="mt-1 text-xs text-slate-400">{{ field.options.helper }}</p>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
