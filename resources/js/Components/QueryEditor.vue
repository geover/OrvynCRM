<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import FilterBuilder from '@/Components/FilterBuilder.vue';
import { api } from '@/lib/api';

// v-model = { scope, data_object_id, definition:{filter,contact_filter,relationship_ids,columns} }
const props = defineProps({ modelValue: Object, preview: { type: Boolean, default: true } });
const emit = defineEmits(['update:modelValue']);

const meta = ref(null);
const result = ref(null);
const running = ref(false);
const error = ref('');

onMounted(async () => { meta.value = await api.get('/queries/meta'); });

const v = computed(() => props.modelValue);
const patch = (p) => emit('update:modelValue', { ...v.value, ...p });
const patchDef = (p) => patch({ definition: { ...v.value.definition, ...p } });

const object = computed(() => meta.value?.objects.find((o) => o.id === Number(v.value.data_object_id)));
const contactFields = computed(() => meta.value?.contact_fields ?? []);
const columnChoices = computed(() => (v.value.scope === 'contact' ? contactFields.value.filter((f) => f.key !== 'tag') : object.value?.fields ?? []));
const filterFields = computed(() => object.value?.fields ?? []);

function setScope(scope) {
    patch({ scope, data_object_id: scope === 'contact' ? null : (meta.value.objects[0]?.id ?? null), definition: {} });
    result.value = null;
}
function setObject(id) {
    patch({ data_object_id: Number(id), definition: {} });
    result.value = null;
}
const toggle = (list, val) => (list.includes(val) ? list.filter((x) => x !== val) : [...list, val]);
const columns = computed(() => v.value.definition?.columns ?? []);
const relIds = computed(() => v.value.definition?.relationship_ids ?? []);

async function run() {
    running.value = true; error.value = '';
    try {
        const def = { ...v.value.definition, ...(v.value.scope === 'data_object' ? { data_object_id: Number(v.value.data_object_id) } : {}) };
        result.value = await api.post('/queries/preview', { scope: v.value.scope, definition: def });
    } catch (e) { error.value = e.message; result.value = null; } finally { running.value = false; }
}
watch(() => v.value.scope, () => { result.value = null; });
defineExpose({ run });
</script>

<template>
    <div v-if="meta" class="space-y-6">
        <section class="card space-y-4 p-5">
            <h3 class="text-sm font-semibold">What are you searching?</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Search in</label>
                    <select :value="v.scope" class="input" @change="setScope($event.target.value)"><option value="contact">Contacts</option><option value="data_object" :disabled="!meta.objects.length">Data objects</option></select></div>
                <div v-if="v.scope === 'data_object'"><label class="label">Data object type</label>
                    <select :value="v.data_object_id" class="input" @change="setObject($event.target.value)"><option v-for="o in meta.objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
            </div>
        </section>

        <section v-if="v.scope === 'data_object' && object" class="card space-y-3 p-5">
            <h3 class="text-sm font-semibold">{{ object.name }} criteria</h3>
            <FilterBuilder v-if="filterFields.length" :model-value="v.definition?.filter ?? { match: 'all', rules: [] }" :fields="filterFields" @update:model-value="(f) => patchDef({ filter: f })" />
        </section>

        <section class="card space-y-3 p-5">
            <h3 class="text-sm font-semibold">{{ v.scope === 'contact' ? 'Contact criteria' : 'Linked contact criteria' }} <span class="font-normal text-slate-400">(optional)</span></h3>
            <FilterBuilder :model-value="v.definition?.contact_filter ?? { match: 'all', rules: [] }" :fields="contactFields" @update:model-value="(f) => patchDef({ contact_filter: f })" />
            <div v-if="v.scope === 'data_object' && object?.relationships.length">
                <label class="label mt-3">Only contacts linked as</label>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <label v-for="r in object.relationships" :key="r.id" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="relIds.includes(r.id)" @change="patchDef({ relationship_ids: toggle(relIds, r.id) })" /> {{ r.label }}</label>
                </div>
            </div>
        </section>

        <section class="card space-y-3 p-5">
            <h3 class="text-sm font-semibold">Result columns</h3>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <label v-for="f in columnChoices" :key="f.key" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="columns.includes(f.key)" @change="patchDef({ columns: toggle(columns, f.key) })" /> {{ f.label }}</label>
            </div>
            <p class="text-xs text-slate-400">Leave empty to use the default columns.</p>
        </section>

        <section v-if="preview" class="card">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h3 class="text-sm font-semibold">Preview <span v-if="result" class="badge ml-2">{{ result.total.toLocaleString() }} match{{ result.total === 1 ? '' : 'es' }}</span></h3>
                <button type="button" class="btn-outline" :disabled="running" @click="run">{{ running ? 'Running…' : 'Run query' }}</button>
            </div>
            <p v-if="error" class="px-5 py-3 text-sm text-red-600">{{ error }}</p>
            <div v-if="result" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th v-for="c in result.columns" :key="c.key" class="px-4 py-2.5">{{ c.label }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><tr v-for="r in result.rows" :key="r.id"><td v-for="c in result.columns" :key="c.key" class="px-4 py-2.5">{{ Array.isArray(r.values[c.key]) ? r.values[c.key].join(', ') : r.values[c.key] }}</td></tr>
                        <tr v-if="!result.rows.length"><td :colspan="result.columns.length" class="px-4 py-8 text-center text-slate-400">No matches.</td></tr></tbody>
                </table>
                <p v-if="result.total > result.rows.length" class="border-t border-slate-100 px-5 py-2 text-xs text-slate-400">Showing the first {{ result.rows.length }}.</p>
            </div>
        </section>
    </div>
    <p v-else class="text-sm text-slate-400">Loading…</p>
</template>
