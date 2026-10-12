<script setup>
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ChevronDown, ChevronRight, Plus, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

const props = defineProps({ formId: { type: Number, default: null } });

const CONTACT = ['first_name', 'middle_name', 'last_name', 'title', 'email', 'phone', 'company_name', 'job_title', 'website'];
const TYPES = [['text', 'Text'], ['textarea', 'Long text'], ['email', 'Email'], ['phone', 'Phone'], ['number', 'Number'], ['date', 'Date'], ['select', 'Dropdown'], ['checkbox', 'Checkbox'], ['url', 'URL']];

const form = ref({ name: '', data_object_id: '', status: 'active' });
const settings = ref({ submit_label: 'Submit', thank_you: 'Thank you! We will be in touch.', redirect_url: '', relationship_id: '', notify: '' });
const columns = ref(1);
const fields = ref([]);
const objects = ref([]);
const open = ref({});
const errors = ref({});
const saving = ref(false);
let uid = 0;

const object = computed(() => objects.value.find((o) => o.id === Number(form.value.data_object_id)));
const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1');

const row = (f = {}) => ({ uid: ++uid, key: f.key ?? '', label: f.label ?? '', type: f.type ?? 'text', required: !!f.required, placeholder: f.placeholder ?? '', tooltip: f.tooltip ?? '', options: (f.options ?? []).join('\n'), map_to: f.map_to ?? '', group: f.group ?? '', touched: !!f.key });

const ready = ref(false);
onMounted(async () => {
    objects.value = (await api.get('/data-objects')).data;
    if (!props.formId) { ready.value = true; fields.value = [row({ key: 'first_name', label: 'First name', required: true, map_to: 'contact.first_name' }), row({ key: 'email', label: 'Email', type: 'email', required: true, map_to: 'contact.email' })]; return; }
    const f = (await api.get(`/forms/${props.formId}`)).data;
    form.value = { name: f.name, data_object_id: f.data_object_id ?? '', status: f.status };
    columns.value = f.schema.columns ?? 1;
    settings.value = { submit_label: f.settings?.submit_label ?? 'Submit', thank_you: f.settings?.thank_you ?? '', redirect_url: f.settings?.redirect_url ?? '', relationship_id: f.settings?.relationship_id ?? '', notify: (f.settings?.notify_emails ?? []).join(', ') };
    fields.value = f.schema.fields.map(row);
    ready.value = true;
});

const add = () => { const r = row(); fields.value.push(r); open.value[r.uid] = true; };
const move = (i, d) => { const j = i + d; if (j >= 0 && j < fields.value.length) [fields.value[i], fields.value[j]] = [fields.value[j], fields.value[i]]; };
const labelChanged = (f) => { if (!f.touched) f.key = slug(f.label); };

const targets = computed(() => [...CONTACT.map((k) => ({ v: `contact.${k}`, l: `Contact · ${k.replace(/_/g, ' ')}` })), ...(object.value?.fields ?? []).map((x) => ({ v: `record.${x.key}`, l: `${object.value.name} · ${x.label}` }))]);

async function save() {
    saving.value = true; errors.value = {};
    const body = {
        name: form.value.name, status: form.value.status, data_object_id: form.value.data_object_id || null,
        schema: { columns: columns.value, fields: fields.value.map((f) => ({ key: f.key, label: f.label, type: f.type, required: f.required, placeholder: f.placeholder || null, tooltip: f.tooltip || null,
            options: f.type === 'select' ? f.options.split('\n').map((x) => x.trim()).filter(Boolean) : null, map_to: f.map_to || null, group: f.group || null })) },
        settings: { submit_label: settings.value.submit_label || null, thank_you: settings.value.thank_you || null, redirect_url: settings.value.redirect_url || null,
            relationship_id: settings.value.relationship_id || null, notify_emails: settings.value.notify.split(',').map((x) => x.trim()).filter(Boolean) },
    };
    try { await (props.formId ? api.put(`/forms/${props.formId}`, body) : api.post('/forms', body)); run(async () => {}, 'Form saved'); router.visit('/admin/forms'); }
    catch (e) { errors.value = e.errors ?? {}; saving.value = false; run(async () => { throw new Error(Object.values(errors.value)[0]?.[0] ?? e.message); }); }
}
</script>

<template>
    <AdminPage :title="formId ? 'Edit form' : 'New form'" :crumbs="[{ label: 'Form builder', href: '/admin/forms' }]">
        <template #actions><button class="btn-primary" :disabled="saving || !ready || !form.name" @click="save">{{ saving ? 'Saving…' : 'Save form' }}</button></template>
        <div v-if="ready" class="grid max-w-4xl gap-6">
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Basic information</h2>
                <div class="grid gap-3 sm:grid-cols-3"><div class="sm:col-span-2"><label class="label">Form name</label><input v-model="form.name" class="input" required /></div>
                    <div><label class="label">Status</label><select v-model="form.status" class="input"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><label class="label">Also create a data object</label><select v-model="form.data_object_id" class="input"><option value="">Contact only</option><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                    <div v-if="object"><label class="label">Link contact as</label><select v-model="settings.relationship_id" class="input"><option value="">First available</option><option v-for="r in object.relationships" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                    <div><label class="label">Columns</label><select v-model.number="columns" class="input"><option :value="1">1</option><option :value="2">2</option><option :value="3">3</option></select></div>
                </div>
            </section>

            <section class="card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><h2 class="text-sm font-semibold">Fields <span class="badge ml-1">{{ fields.length }}</span></h2><button class="btn-outline" @click="add"><Plus class="size-4" /> Add field</button></div>
                <div v-for="(f, i) in fields" :key="f.uid" class="border-b border-slate-100 last:border-0">
                    <div class="flex items-center gap-2 px-3 py-2.5">
                        <button class="btn-ghost !px-1.5" @click="open[f.uid] = !open[f.uid]"><component :is="open[f.uid] ? ChevronDown : ChevronRight" class="size-4" /></button>
                        <input v-model="f.label" class="input !py-1.5" placeholder="Field label" @input="labelChanged(f)" />
                        <select v-model="f.type" class="input !w-36 !py-1.5"><option v-for="[v, l] in TYPES" :key="v" :value="v">{{ l }}</option></select>
                        <label class="flex shrink-0 items-center gap-1.5 text-sm"><input v-model="f.required" type="checkbox" class="rounded" /> Required</label>
                        <button class="btn-ghost !px-1.5" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="size-4" /></button><button class="btn-ghost !px-1.5" :disabled="i === fields.length - 1" @click="move(i, 1)"><ArrowDown class="size-4" /></button>
                        <button class="btn-ghost !px-1.5 text-red-600" @click="fields.splice(i, 1)"><Trash2 class="size-4" /></button>
                    </div>
                    <p v-if="errors[`schema.fields.${i}.key`] || errors[`schema.fields.${i}.label`]" class="px-14 pb-2 text-xs text-red-600">{{ (errors[`schema.fields.${i}.key`] || errors[`schema.fields.${i}.label`])[0] }}</p>
                    <div v-if="open[f.uid]" class="grid gap-3 bg-slate-50 px-14 py-4 sm:grid-cols-2">
                        <div><label class="label">Field key</label><input v-model="f.key" class="input font-mono" @input="f.touched = true" /></div>
                        <div><label class="label">Save value to</label><select v-model="f.map_to" class="input"><option value="">Automatic</option><option v-for="t in targets" :key="t.v" :value="t.v">{{ t.l }}</option></select></div>
                        <div><label class="label">Placeholder</label><input v-model="f.placeholder" class="input" /></div><div><label class="label">Tooltip / help text</label><input v-model="f.tooltip" class="input" /></div>
                        <div><label class="label">Group heading</label><input v-model="f.group" class="input" placeholder="e.g. Contact information" /></div>
                        <div v-if="f.type === 'select'" class="sm:col-span-2"><label class="label">Options (one per line)</label><textarea v-model="f.options" rows="3" class="input font-mono" /></div>
                    </div>
                </div>
            </section>

            <section class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold">After submitting</h2>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Submit button label</label><input v-model="settings.submit_label" class="input" /></div><div><label class="label">Redirect URL <span class="normal-case text-slate-400">(optional)</span></label><input v-model="settings.redirect_url" class="input" placeholder="https://" /></div></div>
                <div><label class="label">Thank-you message</label><input v-model="settings.thank_you" class="input" /></div>
                <div><label class="label">Notify these emails on each submission</label><input v-model="settings.notify" class="input" placeholder="sales@example.com, owner@example.com" /></div>
            </section>
        </div>
    </AdminPage>
</template>
