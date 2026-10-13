<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminPage from '@/Components/AdminPage.vue';
import QueryEditor from '@/Components/QueryEditor.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

const props = defineProps({ queryId: { type: Number, default: null } });
const name = ref('');
const q = ref({ scope: 'contact', data_object_id: null, definition: {} });
const ready = ref(!props.queryId);
const editor = ref(null);

onMounted(async () => {
    if (!props.queryId) return;
    const found = (await api.get('/queries')).data.find((x) => x.id === props.queryId);
    name.value = found.name;
    q.value = { scope: found.scope, data_object_id: found.data_object_id, definition: found.definition };
    ready.value = true;
});

async function save() {
    const body = { name: name.value, scope: q.value.scope, data_object_id: q.value.data_object_id, definition: q.value.definition };
    if (await run(() => (props.queryId ? api.put(`/queries/${props.queryId}`, body) : api.post('/queries', body)), 'Query saved')) router.visit('/admin/queries');
}
</script>

<template>
    <AdminPage :title="queryId ? 'Edit query' : 'New query'" :crumbs="[{ label: 'Query builder', href: '/admin/queries' }]">
        <template #actions><button class="btn-primary" :disabled="!name.trim()" @click="save">Save query</button></template>
        <div v-if="ready" class="max-w-4xl space-y-6">
            <section class="card p-5"><label class="label">Query name</label><input v-model="name" class="input" placeholder="e.g. Open deals over $5,000" required /></section>
            <QueryEditor ref="editor" v-model="q" />
        </div>
    </AdminPage>
</template>
