<script setup>
import { onMounted, ref } from 'vue';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';

const items = ref([]);
const loading = ref(true);
onMounted(async () => { items.value = (await api.get('/custom-tabs')).data; loading.value = false; });

const add = () => items.value.push({ id: null, title: '', type: 'iframe', content: '' });
const move = (i, d) => { const j = i + d; if (j >= 0 && j < items.value.length) [items.value[i], items.value[j]] = [items.value[j], items.value[i]]; };
async function remove(i) {
    if (items.value[i].id && !(await confirmDialog({ title: `Delete “${items.value[i].title}”?`, danger: true, confirmLabel: 'Delete' }))) return;
    items.value.splice(i, 1);
}
async function save() {
    const r = await run(() => api.put('/custom-tabs', { items: items.value.map(({ id, title, type, content }) => ({ id, title, type, content })) }), 'Custom tabs saved');
    if (r?.data) items.value = r.data;
}
</script>

<template>
    <AdminPage title="Custom tabs" subtitle="Tabs with customised content in the contact page sidebar.">
        <template #actions><button class="btn-outline" :disabled="loading" @click="add"><Plus class="size-4" /> Add tab</button><button class="btn-primary" :disabled="loading" @click="save">Save changes</button></template>
        <div v-if="!loading" class="max-w-3xl space-y-4">
            <div v-for="(t, i) in items" :key="i" class="card space-y-3 p-5">
                <div class="flex gap-2">
                    <input v-model="t.title" class="input" placeholder="Tab title" /><select v-model="t.type" class="input !w-40"><option value="iframe">Embed a URL</option><option value="html">Static HTML</option></select>
                    <button class="btn-ghost !px-1.5" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="size-4" /></button>
                    <button class="btn-ghost !px-1.5" :disabled="i === items.length - 1" @click="move(i, 1)"><ArrowDown class="size-4" /></button>
                    <button class="btn-ghost !px-1.5 text-red-600" @click="remove(i)"><Trash2 class="size-4" /></button>
                </div>
                <input v-if="t.type === 'iframe'" v-model="t.content" class="input font-mono" placeholder="https://… (use {{contact.id}} or {{contact.email}} to pass the contact)" />
                <textarea v-else v-model="t.content" rows="6" class="input font-mono" placeholder="<h2>Hello</h2>" />
                <p class="text-xs text-slate-400">{{ t.type === 'iframe' ? 'Must be https. The site must allow being embedded in a frame.' : 'Rendered in a sandboxed frame — scripts are disabled.' }}</p>
            </div>
            <p v-if="!items.length && !loading" class="card px-5 py-10 text-center text-sm text-slate-400">No custom tabs yet.</p>
        </div>
    </AdminPage>
</template>
