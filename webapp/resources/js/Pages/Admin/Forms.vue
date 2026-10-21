<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CheckCheck, Copy, FormInput, Inbox, Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import ItemCollection from '@/Components/ItemCollection.vue';
import Modal from '@/Components/Modal.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import { api } from '@/lib/api';
import { confirmDialog, run, toast } from '@/lib/ui';

const forms = ref(null);
const embed = ref(null);
const active = computed(() => (forms.value ?? []).filter((f) => f.status === 'active').length);
const submissions = computed(() => (forms.value ?? []).reduce((n, f) => n + (f.submissions_count ?? 0), 0));
const load = async () => (forms.value = (await api.get('/forms')).data);
onMounted(load);

async function remove(f) {
    if (await confirmDialog({ title: `Delete “${f.name}”?`, message: 'Pages that embed this form will stop working. Past submissions are deleted too.', danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/forms/${f.id}`), 'Form deleted'); load(); }
}
const copy = async (text) => { try { await navigator.clipboard.writeText(text); toast('Copied to clipboard'); } catch { toast('Select the text and copy it manually', 'error'); } };
</script>

<template>
    <AdminPage title="Form builder" subtitle="Build forms to embed in your website with custom fields.">
        <template #actions><Link href="/admin/forms/new" class="btn-primary"><Plus class="size-4" /> Create form</Link></template>
                <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-3" data-testid="forms-summary" aria-label="Forms in numbers">
            <TicketTile :icon="FormInput" tone="bg-indigo-100 text-indigo-700" label="Forms" :value="forms?.length ?? 0" hint="you have built" />
            <TicketTile :icon="CheckCheck" tone="bg-emerald-100 text-emerald-700" label="Active" :value="active" hint="accepting answers" />
            <TicketTile class="col-span-2 lg:col-span-1" :icon="Inbox" tone="bg-sky-100 text-sky-700" label="Submissions" :value="submissions.toLocaleString()" hint="received in all" />
        </section>
        <ItemCollection :items="forms" storage-key="forms" :icon="FormInput" :search-text="(f) => f.name" search-label="Find a form…" empty-title="No forms yet" empty-text="Build a form to put on your website. Every answer arrives as a contact in your own CRM.">
            <template #title="{ item: f }"><Link :href="`/admin/forms/${f.id}`" class="hover:text-brand-700">{{ f.name }}</Link></template>
            <template #badges="{ item: f }"><span :class="f.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : ''" class="badge">{{ f.status }}</span></template>
            <template #meta="{ item: f }">{{ f.schema.fields.length }} fields · {{ f.submissions_count }} submissions · {{ f.data_object ? `creates ${f.data_object.name}` : 'contact only' }}</template>
            <template #actions="{ item: f }"><button class="btn-outline" @click="embed = f">Embed code</button><a :href="f.public_url" target="_blank" rel="noopener" class="btn-outline">Open</a><Link :href="`/admin/forms/${f.id}`" class="btn-outline">Edit</Link>
                <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete" @click="remove(f)"><Trash2 class="size-4" /></button></template>
            <template #empty><Link href="/admin/forms/new" class="btn-primary"><Plus class="size-4" /> Create form</Link></template>
        </ItemCollection>
        <Modal v-if="embed" :title="`Embed “${embed.name}”`" width="max-w-2xl" @close="embed = null">
            <div class="space-y-4 text-sm">
                <div v-for="[label, code] in [['JavaScript (recommended — auto-resizes)', embed.embed_script], ['HTML iframe', embed.embed_iframe], ['Direct link', embed.public_url]]" :key="label">
                    <div class="mb-1 flex items-center justify-between"><span class="label !mb-0">{{ label }}</span><button class="btn-ghost !px-2 !py-1" @click="copy(code)"><Copy class="size-4" /> Copy</button></div>
                    <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ code }}</pre>
                </div>
            </div>
        </Modal>
    </AdminPage>
</template>
