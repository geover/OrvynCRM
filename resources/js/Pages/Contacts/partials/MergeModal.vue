<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

// De-duplicate: the OPEN contact survives; the chosen duplicate is folded into it.
const props = defineProps({ contact: Object });
const emit = defineEmits(['close', 'merged']);
const search = ref('');
const found = ref([]);
const dup = ref(null);
const notesTasks = ref('transfer');
const dataObjects = ref('transfer');
const busy = ref(false);

let t;
watch(search, () => {
    clearTimeout(t);
    t = setTimeout(async () => { found.value = search.value.length > 1 ? (await api.get('/contacts', { 'filter[q]': search.value, per_page: 8 })).data.filter((c) => c.id !== props.contact.id) : []; }, 250);
});
async function merge() {
    busy.value = true;
    const ok = await run(() => api.post(`/contacts/${props.contact.id}/merge`, { duplicate_id: dup.value.id, notes_tasks: notesTasks.value, data_objects: dataObjects.value }), 'Contacts merged');
    busy.value = false;
    if (ok) emit('merged');
}
</script>

<template>
    <Modal title="Merge duplicate contact" @close="emit('close')">
        <div class="space-y-4 text-sm">
            <p class="text-slate-600">Primary contact: <b>{{ contact.full_name }}</b>. Empty fields on the primary are filled from the duplicate, tags and emails move across, and the duplicate is removed.</p>
            <div v-if="!dup">
                <label class="label">Find the duplicate</label>
                <input v-model="search" class="input" placeholder="Name or email…" autofocus />
                <div v-if="found.length" class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    <button v-for="c in found" :key="c.id" class="flex w-full justify-between px-3 py-2 text-left hover:bg-slate-50" @click="dup = c"><span class="font-medium">{{ c.full_name }}</span><span class="text-xs text-slate-400">{{ c.email }}</span></button>
                </div>
            </div>
            <template v-else>
                <div class="flex items-center justify-between rounded-lg bg-amber-50 px-3 py-2 text-amber-900"><span>Duplicate: <b>{{ dup.full_name }}</b> {{ dup.email }}</span><button class="underline" @click="dup = null">change</button></div>
                <div><label class="label">Notes &amp; tasks of the duplicate</label><select v-model="notesTasks" class="input"><option value="transfer">Move to primary contact</option><option value="none">Leave behind (not moved)</option></select></div>
                <div><label class="label">Data object links of the duplicate</label><select v-model="dataObjects" class="input"><option value="transfer">Move to primary contact</option><option value="none">Do not move</option></select></div>
            </template>
        </div>
        <template #footer>
            <button class="btn-ghost" @click="emit('close')">Cancel</button>
            <button class="btn-primary !bg-red-600 hover:!bg-red-700" :disabled="!dup || busy" @click="merge">{{ busy ? 'Merging…' : 'Merge contacts' }}</button>
        </template>
    </Modal>
</template>
