<script setup>
import { onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';

const props = defineProps({ contactId: Number });
const data = ref(null);
const types = ref([]);
const typeId = ref('');
const search = ref('');
const found = ref([]);

async function load() { data.value = await api.get(`/contacts/${props.contactId}/relationships`); }
onMounted(async () => {
    load();
    types.value = (await api.get('/contact-relationship-types')).data.filter((t) => t.kind === 'direct');
    typeId.value = types.value[0]?.id ?? '';
});
let t;
watch(search, () => {
    clearTimeout(t);
    t = setTimeout(async () => { found.value = search.value.length > 1 ? (await api.get('/contacts', { 'filter[q]': search.value, per_page: 6 })).data.filter((c) => c.id !== props.contactId) : []; }, 250);
});
async function add(c) {
    if (await run(() => api.post(`/contacts/${props.contactId}/relationships`, { related_contact_id: c.id, type_id: typeId.value }), `Linked to ${c.full_name}`)) { search.value = ''; found.value = []; load(); }
}
async function remove(r) {
    if (!(await confirmDialog({ title: `Disconnect ${r.contact.name}?`, confirmLabel: 'Disconnect', danger: true }))) return;
    await run(() => api.delete(`/contacts/${props.contactId}/relationships/${r.id}`), 'Disconnected'); load();
}
</script>

<template>
    <div v-if="data" class="grid gap-6 lg:grid-cols-2">
        <section class="card">
            <h2 class="card-title">Direct relationships</h2>
            <ul class="divide-y divide-slate-100">
                <li v-for="r in data.direct" :key="r.id" class="flex items-center justify-between px-5 py-3 text-sm">
                    <div><Link :href="`/contacts/${r.contact.id}`" class="font-medium text-brand-700 hover:underline">{{ r.contact.name }}</Link>
                        <div class="text-xs text-slate-500">{{ r.direction === 'out' ? r.type : `${r.type} of` }} · {{ r.contact.email }}</div></div>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(r)"><Trash2 class="size-4" /></button>
                </li>
                <li v-if="!data.direct.length" class="px-5 py-6 text-center text-sm text-slate-400">No direct relationships.</li>
            </ul>
            <div class="space-y-2 border-t border-slate-100 p-4">
                <p v-if="!types.length" class="text-xs text-slate-400">No relationship types yet. An admin can add them under Company settings → Contact relationships.</p>
                <template v-else>
                    <div class="flex gap-2">
                        <select v-model="typeId" class="input !w-40"><option v-for="t in types" :key="t.id" :value="t.id">{{ t.label }}</option></select>
                        <input v-model="search" class="input" placeholder="Find a contact to link…" />
                    </div>
                    <div v-if="found.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                        <button v-for="c in found" :key="c.id" class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-slate-50" @click="add(c)"><span>{{ c.full_name }}</span><span class="text-xs text-slate-400">{{ c.email }}</span></button>
                    </div>
                </template>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title">Indirect relationships <span class="font-normal text-slate-400">· via shared data</span></h2>
            <ul class="divide-y divide-slate-100">
                <li v-for="(r, i) in data.indirect" :key="i" class="px-5 py-3 text-sm">
                    <Link :href="`/contacts/${r.id}`" class="font-medium text-brand-700 hover:underline">{{ r.name }}</Link>
                    <div class="text-xs text-slate-500">{{ r.relationship }} on a <Link :href="`/objects/${r.object_id}`" class="underline">{{ r.object }}</Link> item · {{ r.email }}</div>
                </li>
                <li v-if="!data.indirect.length" class="px-5 py-6 text-center text-sm text-slate-400">No contacts share data with this one.</li>
            </ul>
        </section>
    </div>
</template>
