<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import QueryEditor from '@/Components/QueryEditor.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

// Old "Advanced Search": run a saved query, or build an ad-hoc one (optionally saving it).
const emit = defineEmits(['close']);
const mode = ref('saved');
const queries = ref([]);
const picked = ref('');
const result = ref(null);
const running = ref(false);
const q = ref({ scope: 'contact', data_object_id: null, definition: {} });
const saveName = ref('');
const editor = ref(null);

onMounted(async () => { queries.value = (await api.get('/queries')).data; picked.value = queries.value[0]?.id ?? ''; if (!queries.value.length) mode.value = 'build'; });

async function runSaved() {
    if (!picked.value) return;
    running.value = true;
    try { result.value = await api.get(`/queries/${picked.value}/run`, { limit: 100 }); } finally { running.value = false; }
}
async function runBuilt() {
    running.value = true;
    try {
        const def = { ...q.value.definition, ...(q.value.scope === 'data_object' ? { data_object_id: Number(q.value.data_object_id) } : {}) };
        result.value = await api.post('/queries/preview', { scope: q.value.scope, definition: def, limit: 100 });
    } catch (e) { run(async () => { throw e; }); } finally { running.value = false; }
}
async function saveBuilt() {
    if (!saveName.value.trim()) return;
    const ok = await run(() => api.post('/queries', { name: saveName.value, scope: q.value.scope, data_object_id: q.value.data_object_id, definition: q.value.definition }), 'Query saved');
    if (ok) { saveName.value = ''; queries.value = (await api.get('/queries')).data; }
}
function open(r) {
    emit('close');
    router.visit(r.contact_id ? `/contacts/${r.contact_id}` : r.contacts?.[0] ? `/contacts/${r.contacts[0].id}` : `/objects/${r.data_object_id}`);
}
const cell = (v) => (Array.isArray(v) ? v.join(', ') : v);
</script>

<template>
    <Modal title="Advanced search" width="max-w-4xl" @close="emit('close')">
        <div class="mb-4 flex w-fit rounded-lg bg-slate-100 p-1 text-sm">
            <button :class="mode === 'saved' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="mode = 'saved'; result = null">Saved queries</button>
            <button :class="mode === 'build' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="mode = 'build'; result = null">Build a search</button>
        </div>

        <div v-if="mode === 'saved'" class="flex gap-2">
            <select v-model="picked" class="input"><option v-for="x in queries" :key="x.id" :value="x.id">{{ x.name }}</option></select>
            <button class="btn-primary shrink-0" :disabled="!picked || running" @click="runSaved">{{ running ? 'Running…' : 'Run query' }}</button>
        </div>
        <div v-else class="space-y-4">
            <QueryEditor v-model="q" :preview="false" />
            <div class="flex flex-wrap items-center gap-2">
                <button class="btn-primary" :disabled="running" @click="runBuilt">{{ running ? 'Running…' : 'Run query' }}</button>
                <input v-model="saveName" class="input !w-56" placeholder="Name to save this search…" /><button class="btn-outline" :disabled="!saveName.trim()" @click="saveBuilt">Save as new query</button>
            </div>
        </div>

        <div v-if="result" class="mt-5 overflow-hidden rounded-lg border border-slate-200">
            <div class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-sm font-medium">{{ result.total.toLocaleString() }} result{{ result.total === 1 ? '' : 's' }}<span v-if="result.total > result.rows.length" class="font-normal text-slate-400"> · showing the first {{ result.rows.length }}</span></div>
            <div class="max-h-80 overflow-auto"><table class="w-full text-sm">
                <thead class="sticky top-0 bg-white text-left text-xs uppercase tracking-wide text-slate-500"><tr><th v-for="c in result.columns" :key="c.key" class="px-4 py-2">{{ c.label }}</th><th /></tr></thead>
                <tbody class="divide-y divide-slate-100"><tr v-for="r in result.rows" :key="r.id" class="hover:bg-slate-50"><td v-for="c in result.columns" :key="c.key" class="px-4 py-2">{{ cell(r.values[c.key]) }}</td><td class="px-3 text-right"><button class="text-xs text-brand-700 hover:underline" @click="open(r)">Open</button></td></tr>
                    <tr v-if="!result.rows.length"><td :colspan="result.columns.length + 1" class="px-4 py-8 text-center text-slate-400">No matches.</td></tr></tbody></table></div>
        </div>
    </Modal>
</template>
