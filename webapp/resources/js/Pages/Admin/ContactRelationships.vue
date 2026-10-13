<script setup>
import { computed, onMounted, ref } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

const items = ref([]);
const loading = ref(true);
onMounted(async () => { items.value = (await api.get('/contact-relationship-types')).data; loading.value = false; });
const by = (k) => computed(() => items.value.map((x, i) => [x, i]).filter(([x]) => x.kind === k));
const direct = by('direct');
const indirect = by('indirect');
const add = (kind) => items.value.push({ id: null, label: '', description: '', kind });
const remove = (i) => items.value.splice(i, 1);
async function save() {
    const r = await run(() => api.put('/contact-relationship-types', { items: items.value }), 'Relationships saved');
    if (r?.data) items.value = r.data;
}
</script>

<template>
    <AdminPage title="Contact relationships" subtitle="Manage direct and indirect contact-to-contact relationships.">
        <template #actions><button class="btn-primary" :disabled="loading" @click="save">Save changes</button></template>
        <div v-if="!loading" class="grid max-w-5xl gap-6 lg:grid-cols-2">
            <section v-for="g in [{ k: 'direct', title: 'Direct relationships', list: direct, text: 'Explicit links between two contacts, e.g. Spouse or Manager.' }, { k: 'indirect', title: 'Indirect relationships', list: indirect, text: 'Labels for links that arise through shared data.' }]" :key="g.k" class="card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><div><h2 class="text-sm font-semibold">{{ g.title }}</h2><p class="text-xs text-slate-400">{{ g.text }}</p></div>
                    <button class="btn-outline" @click="add(g.k)"><Plus class="size-4" /> Add</button></div>
                <div v-for="[x, i] in g.list" :key="i" class="flex gap-2 border-b border-slate-100 px-5 py-3 last:border-0">
                    <input v-model="x.label" class="input !w-44" placeholder="Name" /><input v-model="x.description" class="input" placeholder="Description (optional)" />
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(i)"><Trash2 class="size-4" /></button>
                </div>
                <p v-if="!g.list.length" class="px-5 py-8 text-center text-sm text-slate-400">None yet.</p>
            </section>
        </div>
    </AdminPage>
</template>
