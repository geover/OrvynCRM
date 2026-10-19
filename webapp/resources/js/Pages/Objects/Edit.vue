<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ChevronDown, ChevronRight, Plus, Trash2 } from 'lucide-vue-next';
import { api } from '@/lib/api';
import { confirmDialog, run } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// `embedded`: shown in a wide pop-up on the object's own page (no page title or Back link; saving or deleting tells the page instead of leaving it)
const props = defineProps({ objectId: { type: Number, default: null }, embedded: Boolean });
const emit = defineEmits(['saved', 'deleted']);
const isNew = props.objectId === null;

const WIDTHS = [[100, 'Full width (100%)'], [50, 'Half a row (50%)'], [33, 'One third of a row (33%)'], [25, 'One quarter of a row (25%)']];
const TYPES = [
    ['text', 'Text'], ['textarea', 'Long text'], ['number', 'Number'], ['date', 'Date'], ['datetime', 'Date & time'],
    ['email', 'Email'], ['url', 'URL'], ['select', 'Dropdown'], ['multiselect', 'Multi-select'], ['checkbox', 'Checkbox'], ['file', 'File'],
];

const form = ref({ name: '', description: '' });
const fields = ref([]);
const rules = ref({});            // relationship_id -> { enabled, is_exclusive, max_contacts }
const relationships = ref([]);
const newRelationship = ref('');
const open = ref({});             // field uid -> expanded
const saving = ref(false);
const loading = ref(!isNew);
const errors = ref({});
const message = ref('');
let uid = 0;

const slug = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1');
const usesChoices = (f) => ['select', 'multiselect'].includes(f.type);

function toRow(f = {}) {
    const o = f.options ?? {};
    return {
        uid: ++uid, existing: !!f.id, key: f.key ?? '', keyTouched: !!f.id, label: f.label ?? '', type: f.type ?? 'text',
        is_required: !!f.is_required, is_indexed: !!f.is_indexed, show_in_list: !!f.show_in_list, contact_specific: !!f.contact_specific,
        placeholder: o.placeholder ?? '', helper: o.helper ?? '', default: o.default ?? '', section: o.section ?? '', width: [25, 33, 50, 100].includes(Number(o.width)) ? Number(o.width) : 100,
        choices: (o.choices ?? []).join('\n'), extraOptions: Object.fromEntries(Object.entries(o).filter(([k]) => !['placeholder', 'helper', 'default', 'section', 'choices', 'width'].includes(k))),
    };
}

onMounted(async () => {
    relationships.value = (await api.get('/relationships')).data;
    relationships.value.forEach((r) => (rules.value[r.id] = { enabled: false, is_exclusive: false, max_contacts: '' }));
    if (isNew) return;

    const o = (await api.get(`/data-objects/${props.objectId}`)).data;
    form.value = { name: o.name, description: o.description ?? '' };
    fields.value = o.fields.map(toRow);
    o.relationships.forEach((r) => (rules.value[r.id] = { enabled: true, is_exclusive: !!r.pivot.is_exclusive, max_contacts: r.pivot.max_contacts ?? '' }));
    loading.value = false;
});

function addField() {
    const f = toRow();
    fields.value.push(f);
    open.value[f.uid] = true;
}
function labelChanged(f) {
    if (!f.keyTouched) f.key = slug(f.label);
}
function move(i, d) {
    const j = i + d;
    if (j < 0 || j >= fields.value.length) return;
    [fields.value[i], fields.value[j]] = [fields.value[j], fields.value[i]];
}
async function removeField(i) {
    const f = fields.value[i];
    if (f.existing && !(await confirmDialog({ title: `Remove “${f.label}”?`, message: 'Values already saved in records stay in the database but will no longer be shown.', danger: true, confirmLabel: 'Remove field' }))) return;
    fields.value.splice(i, 1);
}
async function addRelationship() {
    if (!newRelationship.value.trim()) return;
    try {
        const r = (await api.post('/relationships', { label: newRelationship.value.trim() })).data;
        relationships.value.push(r);
        rules.value[r.id] = { enabled: true, is_exclusive: false, max_contacts: '' };
        newRelationship.value = '';
    } catch (e) { message.value = e.errors?.label?.[0] ?? e.message; }
}

// deleting a type removes it everywhere: contacts connected through it are disconnected from their items (contacts and items stay)
async function removeRelationship(r) {
    const n = r.links ?? 0;
    const msg = n ? `${n.toLocaleString()} connection${n === 1 ? '' : 's'} between contacts and items use it. They will be disconnected (the contacts and the items stay).` : 'Nothing is connected through it.';
    if (!(await confirmDialog({ title: `Delete the relationship “${r.label}”?`, message: `${msg} Every data object stops listing it, and automations and forms that named it no longer do.`, danger: true, confirmLabel: 'Delete relationship' }))) return;
    if (await run(() => api.delete(`/relationships/${r.id}`), `“${r.label}” deleted`)) {
        relationships.value = relationships.value.filter((x) => x.id !== r.id);
        delete rules.value[r.id];
    }
}

const payload = computed(() => ({
    name: form.value.name,
    description: form.value.description || null,
    fields: fields.value.map((f) => ({
        key: f.key, label: f.label, type: f.type,
        is_required: f.is_required, is_indexed: f.is_indexed, show_in_list: f.show_in_list, contact_specific: f.contact_specific,
        options: {
            ...f.extraOptions,
            ...(f.placeholder && { placeholder: f.placeholder }), ...(f.helper && { helper: f.helper }), ...(f.width !== 100 && { width: f.width }),
            ...(f.default !== '' && { default: f.default }), ...(f.section && { section: f.section }),
            ...(usesChoices(f) && { choices: f.choices.split('\n').map((c) => c.trim()).filter(Boolean) }),
        },
    })),
    relationships: Object.entries(rules.value).filter(([, r]) => r.enabled).map(([id, r]) => ({
        relationship_id: Number(id), is_exclusive: r.is_exclusive, max_contacts: r.max_contacts === '' ? null : Number(r.max_contacts),
    })),
}));

const errorFor = (path) => errors.value[path]?.[0];

async function save() {
    errors.value = {}; message.value = ''; saving.value = true;
    try {
        const saved = isNew ? await api.post('/data-objects', payload.value) : await api.patch(`/data-objects/${props.objectId}`, payload.value);
        if (props.embedded) { emit('saved', saved.data); saving.value = false; } else router.visit(`/objects/${saved.data.id}`);
    } catch (e) {
        errors.value = e.errors ?? {};
        message.value = Object.keys(errors.value).length ? 'Please fix the highlighted problems.' : e.message;
        saving.value = false;
    }
}
async function destroy() {
    if (!(await confirmDialog({ title: `Delete “${form.value.name}”?`, message: 'ALL of its records are deleted too. This cannot be undone.', danger: true, confirmLabel: 'Delete object' }))) return;
    await api.delete(`/data-objects/${props.objectId}`);
    if (props.embedded) emit('deleted'); else router.visit('/objects');
}
</script>

<template>
    <Head v-if="!embedded" :title="isNew ? 'New data object' : `Edit ${form.name}`" />
    <Link v-if="!embedded" :href="isNew ? '/objects' : `/objects/${objectId}`" class="text-sm text-slate-500 hover:text-slate-800">← Back</Link>
    <div v-if="loading" class="mt-4 text-sm text-slate-500">Loading…</div>

    <form v-else :class="embedded ? 'space-y-6' : 'mt-3 max-w-4xl space-y-6'" @submit.prevent="save">
        <div class="flex items-center justify-between">
            <h1 class="page-title">{{ isNew ? 'New data object' : 'Edit data object' }}</h1>
            <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save changes' }}</button>
        </div>
        <p v-if="message" class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ message }}</p>

        <section class="card space-y-4 p-5">
            <div>
                <label class="label">Name</label>
                <input v-model="form.name" class="input" placeholder="e.g. Deals, Properties, Courses" required />
                <p v-if="errorFor('name')" class="mt-1 text-xs text-red-600">{{ errorFor('name') }}</p>
            </div>
            <div><label class="label">Description</label><textarea v-model="form.description" rows="2" class="input" /></div>
        </section>

        <section class="card">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h2 class="text-sm font-semibold">Fields <span class="badge ml-1">{{ fields.length }}</span></h2>
                <button type="button" class="btn-outline" @click="addField"><Plus class="size-4" /> Add field</button>
            </div>

            <div v-if="!fields.length" class="px-5 py-8 text-center text-sm text-slate-400">No fields yet. Add your first field.</div>

            <div v-for="(f, i) in fields" :key="f.uid" class="border-b border-slate-100 last:border-0">
                <div class="flex items-center gap-2 px-3 py-2.5">
                    <button type="button" class="btn-ghost !px-1.5" @click="open[f.uid] = !open[f.uid]">
                        <component :is="open[f.uid] ? ChevronDown : ChevronRight" class="size-4" />
                    </button>
                    <input v-model="f.label" class="input !py-1.5" placeholder="Field label" @input="labelChanged(f)" />
                    <select v-model="f.type" class="input !w-44 !py-1.5"><option v-for="[v, l] in TYPES" :key="v" :value="v">{{ l }}</option></select>
                    <div class="hidden gap-1 sm:flex">
                        <span v-if="f.is_required" class="badge">required</span>
                        <span v-if="f.is_indexed" class="badge !bg-brand-50 !text-brand-700">indexed</span>
                    </div>
                    <button type="button" class="btn-ghost !px-1.5" :disabled="i === 0" title="Move up" @click="move(i, -1)"><ArrowUp class="size-4" /></button>
                    <button type="button" class="btn-ghost !px-1.5" :disabled="i === fields.length - 1" title="Move down" @click="move(i, 1)"><ArrowDown class="size-4" /></button>
                    <button type="button" class="btn-ghost !px-1.5 text-red-600" title="Remove" @click="removeField(i)"><Trash2 class="size-4" /></button>
                </div>
                <p v-if="errorFor(`fields.${i}.label`) || errorFor(`fields.${i}.key`)" class="px-14 pb-2 text-xs text-red-600">
                    {{ errorFor(`fields.${i}.label`) || errorFor(`fields.${i}.key`) }}
                </p>

                <div v-if="open[f.uid]" class="grid gap-4 bg-slate-50 px-14 py-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Field key</label>
                        <input v-model="f.key" class="input font-mono" :disabled="f.existing" @input="f.keyTouched = true" />
                        <p class="mt-1 text-xs text-slate-400">{{ f.existing ? 'Cannot change after creation (it identifies saved values).' : 'Lowercase letters, numbers, underscores.' }}</p>
                    </div>
                    <div><label class="label">Section / group</label><input v-model="f.section" class="input" placeholder="e.g. Billing" /></div>
                    <div><label class="label">Placeholder</label><input v-model="f.placeholder" class="input" /></div>
                    <div><label class="label">Default value</label><input v-model="f.default" class="input" /></div>
                    <div class="sm:col-span-2"><label class="label">Help text</label><input v-model="f.helper" class="input" /></div>
                    <div><label class="label">Width in the form</label>
                        <select v-model.number="f.width" class="input" data-testid="field-width"><option v-for="[v, l] in WIDTHS" :key="v" :value="v">{{ l }}</option></select>
                        <p class="mt-1 text-xs text-slate-400">How much of a row this field takes when an item is added or edited. Fields that fit are placed side by side; on a phone every field is full width.</p></div>
                    <div v-if="usesChoices(f)" class="sm:col-span-2">
                        <label class="label">Choices (one per line)</label>
                        <textarea v-model="f.choices" rows="4" class="input font-mono" placeholder="New&#10;Negotiation&#10;Won" />
                        <p v-if="errorFor(`fields.${i}.options.choices`)" class="mt-1 text-xs text-red-600">{{ errorFor(`fields.${i}.options.choices`) }}</p>
                    </div>
                    <div class="grid gap-2 text-sm sm:col-span-2 sm:grid-cols-2">
                        <label class="flex items-center gap-2"><input v-model="f.is_required" type="checkbox" class="rounded" /> Required</label>
                        <label class="flex items-center gap-2"><input v-model="f.show_in_list" type="checkbox" class="rounded" /> Show as a column in the list</label>
                        <label class="flex items-start gap-2"><input v-model="f.is_indexed" type="checkbox" class="mt-1 rounded" />
                            <span>Make searchable &amp; filterable fast<span class="block text-xs text-slate-400">Turn on for fields used in filters, sorting or automations. Existing records are re-indexed in the background.</span></span></label>
                        <label class="flex items-start gap-2"><input v-model="f.contact_specific" type="checkbox" class="mt-1 rounded" />
                            <span>Value differs per linked contact<span class="block text-xs text-slate-400">Stored per contact instead of once per record.</span></span></label>
                    </div>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title">Contact relationships</h2>
            <p class="px-5 pt-3 text-xs text-slate-500">How contacts can be linked to records of this object (e.g. “Owner”, “Buyer”).</p>
            <div v-for="r in relationships" :key="r.id" class="flex flex-wrap items-center gap-4 border-b border-slate-100 px-5 py-3 text-sm last:border-0">
                <label class="flex w-48 items-center gap-2 font-medium"><input v-model="rules[r.id].enabled" type="checkbox" class="rounded" /> {{ r.label }}</label>
                <span v-if="r.links" class="text-xs text-slate-400">{{ r.links.toLocaleString() }} connected</span>
                <template v-if="rules[r.id].enabled">
                    <label class="flex items-center gap-2 text-slate-600"><input v-model="rules[r.id].is_exclusive" type="checkbox" class="rounded" /> Only one contact</label>
                    <label class="flex items-center gap-2 text-slate-600">Max contacts
                        <input v-model="rules[r.id].max_contacts" type="number" min="1" class="input !w-20 !py-1" placeholder="∞" /></label>
                </template>
                <button type="button" class="btn-ghost ml-auto !px-1.5 text-slate-400 hover:text-red-600" :title="`Delete ${r.label}`" :aria-label="`Delete relationship ${r.label}`" data-testid="relationship-delete" @click="removeRelationship(r)"><Trash2 class="size-4" /></button>
            </div>
            <div class="flex gap-2 px-5 py-3">
                <input v-model="newRelationship" class="input" placeholder="New relationship type…" @keydown.enter.prevent="addRelationship" />
                <button type="button" class="btn-outline shrink-0" @click="addRelationship">Add</button>
            </div>
        </section>

        <div v-if="!isNew" class="flex justify-between">
            <button type="button" class="btn-ghost text-red-600" @click="destroy"><Trash2 class="size-4" /> Delete object</button>
            <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save changes' }}</button>
        </div>
    </form>
</template>
