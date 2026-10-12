<script setup>
import { onMounted, ref } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

// Admin screen for a settings key that is a list of strings (support emails, excluded domains).
const props = defineProps({ title: String, subtitle: String, settingKey: String, placeholder: String, inputType: { type: String, default: 'text' }, emptyText: String, help: String });
const items = ref([]);
const input = ref('');
const error = ref('');

onMounted(async () => { items.value = (await api.get('/settings')).data[props.settingKey] ?? []; });

async function persist(next, msg) {
    error.value = '';
    try { items.value = (await api.put('/settings', { [props.settingKey]: next })).data[props.settingKey]; msg && run(async () => {}, msg); return true; }
    catch (e) { error.value = Object.values(e.errors ?? {})[0]?.[0] ?? e.message; return false; }
}
async function add() {
    const v = input.value.trim().toLowerCase();
    if (!v) return;
    if (items.value.includes(v)) { error.value = 'Already in the list.'; return; }
    if (await persist([...items.value, v], 'Added')) input.value = '';
}
const remove = (v) => persist(items.value.filter((x) => x !== v), 'Removed');
</script>

<template>
    <AdminPage :title="title" :subtitle="subtitle">
        <div class="card max-w-2xl">
            <form class="flex gap-2 border-b border-slate-100 p-4" @submit.prevent="add">
                <input v-model="input" :type="inputType" class="input" :placeholder="placeholder" /><button class="btn-primary shrink-0"><Plus class="size-4" /> Add</button>
            </form>
            <p v-if="error" class="px-4 pt-3 text-sm text-red-600">{{ error }}</p>
            <ul class="divide-y divide-slate-100">
                <li v-for="v in items" :key="v" class="flex items-center justify-between px-4 py-3 text-sm"><span>{{ v }}</span>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(v)"><Trash2 class="size-4" /></button></li>
                <li v-if="!items.length" class="px-4 py-8 text-center text-sm text-slate-400">{{ emptyText }}</li>
            </ul>
            <p v-if="help" class="border-t border-slate-100 px-4 py-3 text-xs text-slate-400">{{ help }}</p>
        </div>
    </AdminPage>
</template>
