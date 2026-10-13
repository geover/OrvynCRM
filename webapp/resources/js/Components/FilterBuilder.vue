<script setup>
import { computed } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';

defineOptions({ name: 'FilterBuilder' });

// Edits the same filter tree RecordQuery understands:
//   {match:'all'|'any', rules:[{field,op,value} | {match,rules}]}
// fields: [{key,label,type,options?:{choices}}]
const props = defineProps({ modelValue: Object, fields: Array, depth: { type: Number, default: 0 } });
const emit = defineEmits(['update:modelValue']);

const group = computed(() => props.modelValue ?? { match: 'all', rules: [] });
const set = (patch) => emit('update:modelValue', { ...group.value, ...patch });
const setRule = (i, patch) => set({ rules: group.value.rules.map((r, j) => (j === i ? { ...r, ...patch } : r)) });
const remove = (i) => set({ rules: group.value.rules.filter((_, j) => j !== i) });
const addRule = () => set({ rules: [...group.value.rules, { field: props.fields[0]?.key ?? '', op: '=', value: '' }] });
const addGroup = () => set({ rules: [...group.value.rules, { match: 'all', rules: [] }] });

const fieldOf = (key) => props.fields.find((f) => f.key === key);
const OPS = {
    text: [['=', 'is'], ['!=', 'is not'], ['contains', 'contains'], ['starts_with', 'starts with'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
    number: [['=', '='], ['!=', '≠'], ['>', '>'], ['>=', '≥'], ['<', '<'], ['<=', '≤'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
    date: [['=', 'on'], ['>', 'after'], ['>=', 'on or after'], ['<', 'before'], ['<=', 'on or before'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
    select: [['=', 'is'], ['!=', 'is not'], ['in', 'is any of'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
};
const kind = (f) => (['number'].includes(f?.type) ? 'number' : ['date', 'datetime'].includes(f?.type) ? 'date' : ['select', 'multiselect', 'checkbox'].includes(f?.type) ? 'select' : 'text');
const opsFor = (key) => OPS[kind(fieldOf(key))];
const needsValue = (r) => !['empty', 'not_empty'].includes(r.op);
const inputType = (key) => ({ number: 'number', date: 'date', datetime: 'datetime-local' })[fieldOf(key)?.type] ?? 'text';
</script>

<template>
    <div :class="depth ? 'rounded-lg border border-dashed border-slate-300 bg-slate-50/60 p-3' : ''" class="space-y-2">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-slate-500">Match</span>
            <select :value="group.match" class="input !w-auto !py-1" @change="set({ match: $event.target.value })">
                <option value="all">all</option><option value="any">any</option>
            </select>
            <span class="text-slate-500">of the following</span>
        </div>

        <template v-for="(r, i) in group.rules" :key="i">
            <FilterBuilder v-if="r.rules" :model-value="r" :fields="fields" :depth="depth + 1" @update:model-value="(v) => (v === null ? remove(i) : setRule(i, v))" />
            <div v-else class="flex flex-wrap items-center gap-2">
                <select :value="r.field" class="input !w-44 !py-1.5" @change="setRule(i, { field: $event.target.value, op: '=', value: '' })">
                    <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.label }}</option>
                </select>
                <select :value="r.op" class="input !w-36 !py-1.5" @change="setRule(i, { op: $event.target.value })">
                    <option v-for="[v, l] in opsFor(r.field)" :key="v" :value="v">{{ l }}</option>
                </select>
                <template v-if="needsValue(r)">
                    <select v-if="kind(fieldOf(r.field)) === 'select' && r.op !== 'in' && fieldOf(r.field)?.options?.choices" :value="r.value" class="input !w-44 !py-1.5" @change="setRule(i, { value: $event.target.value })">
                        <option value="" /><option v-for="c in fieldOf(r.field).options.choices" :key="c" :value="c">{{ c }}</option>
                    </select>
                    <input v-else-if="r.op === 'in'" :value="Array.isArray(r.value) ? r.value.join(', ') : r.value" class="input !w-56 !py-1.5" placeholder="a, b, c"
                        @input="setRule(i, { value: $event.target.value.split(',').map((x) => x.trim()).filter(Boolean) })" />
                    <input v-else :type="inputType(r.field)" :value="r.value" class="input !w-44 !py-1.5" @input="setRule(i, { value: $event.target.value })" />
                </template>
                <button type="button" class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(i)"><Trash2 class="size-4" /></button>
            </div>
        </template>

        <div class="flex gap-2">
            <button type="button" class="btn-ghost !px-2 !py-1 text-brand-700" @click="addRule"><Plus class="size-4" /> Condition</button>
            <button v-if="depth < 2" type="button" class="btn-ghost !px-2 !py-1 text-slate-500" @click="addGroup"><Plus class="size-4" /> Group</button>
            <button v-if="depth" type="button" class="btn-ghost !px-2 !py-1 text-slate-400" @click="$emit('update:modelValue', null)">Remove group</button>
        </div>
    </div>
</template>
