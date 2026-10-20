<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Download, ExternalLink, History, Mail, MessageSquare, Paperclip, Pencil, Trash2, Users } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import RecordModal from '@/Components/RecordModal.vue';
import EmailComposer from '@/Pages/Contacts/partials/EmailComposer.vue';
import SmsComposer from '@/Pages/Contacts/partials/SmsComposer.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, fmtRelative, run } from '@/lib/ui';

// One card of a workflow board in a wide pop-up: the item's essential information and what can be done with it (move it to another stage, edit, delete, open its contacts).
const props = defineProps({
    object: Object,                          // {id, name}
    cardId: Number,
    stage: String,                           // the key of the column the card is in now
    columns: Array,                          // [{key, label, color}]
    can: Object,                             // {edit, delete}
});
const emit = defineEmits(['close', 'move', 'changed', 'deleted']);

const full = ref(null);                      // the data object with its fields and relationships
const record = ref(null);
const files = ref(null);
const history = ref(null);
const rels = ref([]);
const editing = ref(false);
const base = computed(() => `/data-objects/${props.object.id}/records/${props.cardId}`);
const column = computed(() => props.columns.find((c) => c.key === props.stage));

async function loadRecord() {
    const res = await api.get(base.value);
    record.value = res.data && 'data_object_id' in res.data ? res.data : res;
}
onMounted(async () => {
    [full.value] = await Promise.all([api.get(`/data-objects/${props.object.id}`).then((r) => r.data), loadRecord()]);
    api.get(`${base.value}/attachments`).then((r) => (files.value = r.data)).catch(() => (files.value = []));
    api.get(`${base.value}/history`).then((r) => (history.value = r.data.slice(0, 6))).catch(() => (history.value = []));
    api.get('/relationships').then((r) => (rels.value = r.data)).catch(() => {});
    findContacts();
});

// Writing to the item itself: an e-mail address or phone number in one of its fields. Messages are sent through a contact (opt-outs, history and limits belong to
// the contact), so when a contact has that address or number the message goes through them; otherwise the person's own mail or messaging app is opened.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const digits = (v) => String(v ?? '').replace(/\D/g, '');
const validPhone = (v) => /^\+?[\d\s().-]+$/.test(String(v ?? '').trim()) && digits(v).length >= 7 && digits(v).length <= 15;
const isPhoneField = (f) => f.type === 'text' && /phone|mobile|cell|telephone|\btel\b/i.test(`${f.key} ${f.label}`);
const emailItems = computed(() => fields.value.filter((f) => f.type === 'email' && EMAIL_RE.test(String(record.value?.data?.[f.key] ?? '').trim()))
    .map((f) => ({ label: f.label, value: String(record.value.data[f.key]).trim() })));
const phoneItems = computed(() => fields.value.filter((f) => isPhoneField(f) && validPhone(record.value?.data?.[f.key])).map((f) => ({ label: f.label, value: String(record.value.data[f.key]).trim() })));
const known = ref({});                       // address or number -> the contact who has it (false when nobody does)
async function findContacts() {
    await Promise.allSettled([
        ...emailItems.value.map(async (i) => { known.value[i.value] = (await api.get('/contacts/duplicates', { email: i.value })).data.find((c) => c.email?.toLowerCase() === i.value.toLowerCase()) ?? false; }),
        ...phoneItems.value.map(async (i) => { known.value[i.value] = (await api.get('/contacts/duplicates', { phone: i.value })).data.find((c) => digits(c.phone) === digits(i.value)) ?? false; }),
    ]);
}
const composer = ref(null);                  // {type: 'email'|'sms', contact}
const forContact = (c) => ({ id: c.id, full_name: c.full_name ?? c.name, email: c.email, phone: c.phone });
function writeTo(type, item) {
    const c = known.value[item.value];
    if (c) { composer.value = { type, contact: forContact(c) }; return; }
    window.location.href = type === 'email' ? `mailto:${item.value}` : `sms:${item.value.replace(/[^\d+]/g, '')}`;
}

const fields = computed(() => full.value?.fields ?? []);
const title = computed(() => {
    const f = fields.value.find((x) => x.show_in_list) ?? fields.value[0];
    const v = f ? record.value?.data?.[f.key] : null;
    return v === null || v === undefined || v === '' ? `${props.object.name} #${props.cardId}` : String(v);
});
const filled = (f) => { const v = record.value?.data?.[f.key]; return !(v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)); };
const shown = computed(() => fields.value.filter(filled));
const blank = computed(() => fields.value.filter((f) => !filled(f)));
const wide = (f) => f.type === 'textarea' || f.type === 'multiselect';
const asList = (v) => (Array.isArray(v) ? v : [v]);
const num = (v) => (Number.isFinite(Number(v)) ? Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }) : v);
const relLabel = (id) => rels.value.find((r) => r.id === id)?.label;
const size = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
const eventLabel = { 'record.created': 'Created', 'record.updated': 'Updated', 'record.linked': 'Contact linked' };
const show = (v) => (v === null || v === undefined || v === '' ? 'empty' : Array.isArray(v) ? v.join(', ') : v);

async function remove() {
    if (!(await confirmDialog({ title: 'Delete this item?', message: 'It is removed for every contact it is linked to.', danger: true, confirmLabel: 'Delete' }))) return;
    if (await run(() => api.delete(base.value), 'Deleted')) emit('deleted', props.cardId);
}
async function edited() {
    editing.value = false;
    await loadRecord();
    api.get(`${base.value}/history`).then((r) => (history.value = r.data.slice(0, 6))).catch(() => {});
    emit('changed');
}
</script>

<template>
    <div>
    <Modal :title="object.name" width="max-w-5xl" @close="emit('close')">
        <div v-if="!record || !full" class="py-16 text-center text-sm text-slate-400">Loading…</div>
        <div v-else data-testid="card-modal" class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="truncate text-xl font-semibold" data-testid="card-title">{{ title }}</h3>
                    <p class="mt-1 text-xs text-slate-400">Added {{ fmtDate(record.created_at, true) }} · updated <span :title="fmtDate(record.updated_at, true)">{{ fmtRelative(record.updated_at) }}</span></p>
                </div>
                <Link :href="`/objects/${object.id}`" class="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline"><ExternalLink class="size-3.5" /> All {{ object.name.toLowerCase() }} items</Link>
            </div>

            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <span class="inline-flex size-2.5 rounded-full" :style="{ background: column?.color || '#cbd5e1' }" />
                <label class="text-sm font-medium text-slate-600" for="card-stage">Stage</label>
                <select id="card-stage" :value="stage" class="input !w-52" :disabled="!can.edit" data-testid="card-stage" @change="emit('move', $event.target.value)">
                    <option v-for="c in columns" :key="c.key" :value="c.key">{{ c.label }}</option>
                </select>
                <div class="ml-auto flex flex-wrap gap-2">
                    <button v-if="can.edit" type="button" class="btn-outline" data-testid="card-edit" @click="editing = true"><Pencil class="size-4" /> Edit</button>
                    <button v-if="can.delete" type="button" class="btn-outline !text-red-600 hover:!bg-red-50" data-testid="card-delete" @click="remove"><Trash2 class="size-4" /> Delete</button>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-5">
                <section class="lg:col-span-3">
                    <h4 class="mb-2 text-sm font-semibold text-slate-700">Details</h4>
                    <dl v-if="shown.length" class="grid gap-x-6 gap-y-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2">
                        <div v-for="f in shown" :key="f.key" :class="wide(f) && 'sm:col-span-2'" class="min-w-0">
                            <dt class="text-xs text-slate-400">{{ f.label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-slate-800">
                                <template v-if="f.type === 'checkbox'">{{ record.data[f.key] ? 'Yes' : 'No' }}</template>
                                <template v-else-if="f.type === 'number'">{{ num(record.data[f.key]) }}</template>
                                <template v-else-if="f.type === 'date'">{{ fmtDate(record.data[f.key]) }}</template>
                                <template v-else-if="f.type === 'datetime'">{{ fmtDate(record.data[f.key], true) }}</template>
                                <a v-else-if="f.type === 'email'" :href="`mailto:${record.data[f.key]}`" class="text-brand-700 hover:underline">{{ record.data[f.key] }}</a>
                                <a v-else-if="f.type === 'url'" :href="record.data[f.key]" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">{{ record.data[f.key] }}</a>
                                <span v-else-if="f.type === 'select' || f.type === 'multiselect'" class="flex flex-wrap gap-1"><span v-for="v in asList(record.data[f.key])" :key="v" class="badge">{{ v }}</span></span>
                                <span v-else-if="f.type === 'textarea'" class="whitespace-pre-wrap">{{ record.data[f.key] }}</span>
                                <template v-else>{{ record.data[f.key] }}</template>
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-400">Nothing filled in yet.</p>
                    <p v-if="shown.length && blank.length" class="mt-2 text-xs text-slate-400">Not filled in: {{ blank.map((f) => f.label).join(', ') }}</p>

                    <ul v-if="emailItems.length || phoneItems.length" class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm" data-testid="card-reach">
                        <li v-for="i in emailItems" :key="`e${i.label}${i.value}`" class="flex items-center justify-between gap-3 px-3 py-2">
                            <span class="min-w-0 truncate"><span class="text-xs text-slate-400">{{ i.label }}</span> {{ i.value }}<span v-if="known[i.value]" class="badge ml-2" :title="`Sent through ${known[i.value].full_name}`">{{ known[i.value].full_name }}</span></span>
                            <button type="button" class="btn-outline !py-1" data-testid="item-email" @click="writeTo('email', i)"><Mail class="size-4" /> Email</button>
                        </li>
                        <li v-for="i in phoneItems" :key="`p${i.label}${i.value}`" class="flex items-center justify-between gap-3 px-3 py-2">
                            <span class="min-w-0 truncate"><span class="text-xs text-slate-400">{{ i.label }}</span> {{ i.value }}<span v-if="known[i.value]" class="badge ml-2" :title="`Sent through ${known[i.value].full_name}`">{{ known[i.value].full_name }}</span></span>
                            <button type="button" class="btn-outline !py-1" data-testid="item-sms" @click="writeTo('sms', i)"><MessageSquare class="size-4" /> Text</button>
                        </li>
                    </ul>
                </section>

                <div class="space-y-5 lg:col-span-2">
                    <section>
                        <h4 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Users class="size-4 text-slate-400" /> Contacts <span class="badge">{{ record.contacts?.length ?? 0 }}</span></h4>
                        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
                            <li v-for="c in record.contacts ?? []" :key="c.id" class="flex items-center justify-between gap-2 px-3 py-2">
                                <Link :href="`/contacts/${c.id}`" class="truncate font-medium text-brand-700 hover:underline">{{ c.name }}</Link>
                                <span class="flex shrink-0 items-center gap-1">
                                    <span v-if="relLabel(c.relationship_id)" class="badge">{{ relLabel(c.relationship_id) }}</span>
                                    <button type="button" class="btn-ghost !px-1.5 disabled:opacity-30" :disabled="!c.email" :title="c.email ? `Email ${c.name}` : 'No email address'" :aria-label="`Email ${c.name}`" data-testid="contact-email" @click="composer = { type: 'email', contact: forContact(c) }"><Mail class="size-4" /></button>
                                    <button type="button" class="btn-ghost !px-1.5 disabled:opacity-30" :disabled="!c.phone" :title="c.phone ? `Text ${c.name}` : 'No phone number'" :aria-label="`Text ${c.name}`" data-testid="contact-sms" @click="composer = { type: 'sms', contact: forContact(c) }"><MessageSquare class="size-4" /></button>
                                </span>
                            </li>
                            <li v-if="!record.contacts?.length" class="px-3 py-4 text-center text-slate-400">No contacts linked.</li>
                        </ul>
                    </section>

                    <section>
                        <h4 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Paperclip class="size-4 text-slate-400" /> Attachments <span v-if="files" class="badge">{{ files.length }}</span></h4>
                        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
                            <li v-for="f in files ?? []" :key="f.id" class="flex items-center justify-between gap-2 px-3 py-2">
                                <a :href="`/files${base}/attachments/${f.id}`" class="flex min-w-0 items-center gap-1.5 font-medium text-brand-700 hover:underline"><Download class="size-4 shrink-0" /><span class="truncate">{{ f.filename }}</span></a>
                                <span class="shrink-0 text-xs text-slate-400">{{ size(f.size) }}</span>
                            </li>
                            <li v-if="files && !files.length" class="px-3 py-4 text-center text-slate-400">No attachments.</li>
                            <li v-if="!files" class="px-3 py-4 text-center text-slate-400">Loading…</li>
                        </ul>
                    </section>
                </div>
            </div>

            <section>
                <h4 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700"><History class="size-4 text-slate-400" /> Recent activity</h4>
                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
                    <li v-for="h in history ?? []" :key="h.id" class="px-3 py-2">
                        <div class="flex justify-between gap-2"><span class="font-medium">{{ eventLabel[h.type] ?? h.type }}</span><span class="text-xs text-slate-400" :title="fmtDate(h.at, true)">{{ fmtRelative(h.at) }}{{ h.by ? ` · ${h.by}` : '' }}</span></div>
                        <div v-for="c in h.changes" :key="c.field" class="mt-0.5 text-xs"><span class="text-slate-500">{{ c.field }}:</span> <span class="text-red-600 line-through">{{ show(c.from) }}</span> → <span class="text-emerald-700">{{ show(c.to) }}</span></div>
                    </li>
                    <li v-if="history && !history.length" class="px-3 py-4 text-center text-slate-400">No activity yet.</li>
                    <li v-if="!history" class="px-3 py-4 text-center text-slate-400">Loading…</li>
                </ul>
            </section>
        </div>

    </Modal>
    <RecordModal v-if="editing && full && record" :object="full" :record="record" @close="editing = false" @saved="edited" />
    <EmailComposer v-if="composer?.type === 'email'" :contact="composer.contact" @close="composer = null" />
    <SmsComposer v-if="composer?.type === 'sms'" :contact="composer.contact" @close="composer = null" />
    </div>
</template>
