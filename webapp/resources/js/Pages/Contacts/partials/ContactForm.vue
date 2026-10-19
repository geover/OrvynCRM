<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, Plus, X } from 'lucide-vue-next';
import { api } from '@/lib/api';
import { toastError } from '@/lib/ui';

// Add + edit form for a contact. Emits `saved` with the API resource.
const props = defineProps({ contact: { type: Object, default: null }, compact: Boolean });
const emit = defineEmits(['saved', 'cancel']);

const blank = () => ({
    first_name: '', middle_name: '', last_name: '', title: '', sex: '', job_title: '', company_name: '', website: '', birthday: '',
    email: '', emails: [], phone: '', phones: [], addresses: [{ type: 'BILLING', street1: '', street2: '', city: '', state: '', zip: '', country: '' }],
    owner_id: '', contact_type: '', timezone: '', notes: '', email_opt_out: false, tags: [],
});
// Initialise SYNCHRONOUSLY from the contact being edited. (Loading the owners list is async; populating after it
// used to overwrite anything typed in the meantime and left the form briefly empty.)
function fromContact(c) {
    const b = blank();
    return {
        ...Object.fromEntries(Object.keys(b).map((k) => [k, c[k] ?? b[k]])),
        owner_id: c.owner?.id ?? '', birthday: c.birthday ?? '',
        addresses: c.addresses?.length ? c.addresses.map((a) => ({ ...a })) : b.addresses,
        emails: (c.emails ?? []).map((e) => ({ ...e })), phones: (c.phones ?? []).map((p) => ({ ...p })), tags: [...(c.tags ?? [])],
    };
}
const form = reactive(props.contact ? fromContact(props.contact) : blank());
const users = ref([]);
const errors = ref({});
const saving = ref(false);
const dupes = ref([]);
const tagInput = ref('');

onMounted(async () => { users.value = (await api.get('/users')).data; });

// "Duplicate?" check: runs when email / phone / name is filled in (not when editing an unchanged contact).
let timer;
function checkDupes() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        try {
            dupes.value = (await api.get('/contacts/duplicates', { email: form.email, phone: form.phone, first_name: form.first_name, last_name: form.last_name, except: props.contact?.id })).data;
        } catch { dupes.value = []; }
    }, 400);
}
watch(() => [form.email, form.phone, form.first_name, form.last_name], checkDupes);

const addTag = () => {
    const t = tagInput.value.trim();
    if (t && !form.tags.includes(t)) form.tags.push(t);
    tagInput.value = '';
};

async function save() {
    saving.value = true; errors.value = {};
    const body = {
        ...form, owner_id: form.owner_id || null, birthday: form.birthday || null, timezone: form.timezone || null,
        website: form.website || null, email: form.email || null,
        addresses: form.addresses.filter((a) => Object.entries(a).some(([k, v]) => k !== 'type' && v)),
        emails: form.emails.filter((e) => e.email), phones: form.phones.filter((p) => p.number),
    };
    try {
        const res = props.contact ? await api.patch(`/contacts/${props.contact.id}`, body) : await api.post('/contacts', body);
        emit('saved', res.data);
    } catch (e) {
        errors.value = e.errors ?? {};
        if (!Object.keys(errors.value).length) toastError(e);
    } finally { saving.value = false; }
}
const err = (k) => errors.value[k]?.[0];
const sectionTitle = 'mb-3 mt-6 text-xs font-semibold uppercase tracking-wide text-slate-400 first:mt-0';
defineExpose({ save, saving });
</script>

<template>
    <form class="space-y-1" @submit.prevent="save">
        <div v-if="dupes.length" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
            <div class="flex items-center gap-2 font-medium text-amber-800"><AlertTriangle class="size-4" /> Possible duplicate{{ dupes.length > 1 ? 's' : '' }}</div>
            <ul class="mt-1 space-y-0.5 text-amber-900">
                <li v-for="d in dupes" :key="d.id"><Link :href="`/contacts/${d.id}`" class="underline" target="_blank">{{ d.full_name }}</Link> <span class="text-amber-700">{{ d.email || d.phone }}</span></li>
            </ul>
        </div>

        <h3 :class="sectionTitle">Name</h3>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div><label class="label">Title</label><input v-model="form.title" class="input" placeholder="Mr / Ms" /></div>
            <div><label class="label">First name</label><input v-model="form.first_name" class="input" /><p v-if="err('first_name')" class="mt-1 text-xs text-red-600">{{ err('first_name') }}</p></div>
            <div><label class="label">Middle</label><input v-model="form.middle_name" class="input" /></div>
            <div><label class="label">Last name</label><input v-model="form.last_name" class="input" /></div>
        </div>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div><label class="label">Sex</label><select v-model="form.sex" class="input" data-testid="contact-sex"><option value="">Not specified</option><option value="male">Male</option><option value="female">Female</option></select></div>
            <div><label class="label">Job title</label><input v-model="form.job_title" class="input" /></div>
            <div><label class="label">Company</label><input v-model="form.company_name" class="input" /></div>
            <div><label class="label">Birthday</label><input v-model="form.birthday" type="date" class="input" /></div>
        </div>
        <div class="mt-3"><label class="label">Website</label><input v-model="form.website" class="input" placeholder="https://" /><p v-if="err('website')" class="mt-1 text-xs text-red-600">{{ err('website') }}</p></div>

        <h3 :class="sectionTitle">Email &amp; phone</h3>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" /><p v-if="err('email')" class="mt-1 text-xs text-red-600">{{ err('email') }}</p></div>
            <div><label class="label">Phone</label><input v-model="form.phone" class="input" /></div>
        </div>
        <div v-for="(e, i) in form.emails" :key="'e' + i" class="mt-2 flex gap-2">
            <input v-model="e.email" type="email" class="input" placeholder="Additional email" />
            <select v-model="e.type" class="input !w-32"><option>Work</option><option>Home</option><option>Other</option></select>
            <button type="button" class="btn-ghost !px-1.5" @click="form.emails.splice(i, 1)"><X class="size-4" /></button>
        </div>
        <div v-for="(p, i) in form.phones" :key="'p' + i" class="mt-2 flex gap-2">
            <input v-model="p.number" class="input" placeholder="Additional phone" />
            <input v-model="p.ext" class="input !w-20" placeholder="Ext" />
            <select v-model="p.type" class="input !w-32"><option>Mobile</option><option>Work</option><option>Home</option><option>Fax</option></select>
            <button type="button" class="btn-ghost !px-1.5" @click="form.phones.splice(i, 1)"><X class="size-4" /></button>
        </div>
        <div class="mt-2 flex gap-2">
            <button type="button" class="btn-ghost !px-2 !py-1 text-brand-700" @click="form.emails.push({ email: '', type: 'Work' })"><Plus class="size-4" /> Add email</button>
            <button type="button" class="btn-ghost !px-2 !py-1 text-brand-700" @click="form.phones.push({ number: '', ext: '', type: 'Mobile' })"><Plus class="size-4" /> Add phone</button>
        </div>

        <h3 :class="sectionTitle">Addresses</h3>
        <div v-for="(a, i) in form.addresses" :key="'a' + i" class="mb-3 rounded-lg border border-slate-200 p-3">
            <div class="mb-2 flex items-center justify-between">
                <select v-model="a.type" class="input !w-36 !py-1"><option value="BILLING">Billing</option><option value="SHIPPING">Shipping</option><option value="OTHER">Other</option></select>
                <button v-if="form.addresses.length > 1" type="button" class="btn-ghost !px-1.5" @click="form.addresses.splice(i, 1)"><X class="size-4" /></button>
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <input v-model="a.street1" class="input" placeholder="Address 1" /><input v-model="a.street2" class="input" placeholder="Address 2" />
                <input v-model="a.city" class="input" placeholder="City" /><input v-model="a.state" class="input" placeholder="State / Region" />
                <input v-model="a.zip" class="input" placeholder="Postal code" /><input v-model="a.country" class="input" placeholder="Country" />
            </div>
        </div>
        <button v-if="form.addresses.length < 3" type="button" class="btn-ghost !px-2 !py-1 text-brand-700" @click="form.addresses.push({ type: 'SHIPPING', street1: '', street2: '', city: '', state: '', zip: '', country: '' })"><Plus class="size-4" /> Add address</button>

        <template v-if="!compact">
            <h3 :class="sectionTitle">Status</h3>
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label class="label">Owner</label><select v-model="form.owner_id" class="input"><option value="">Unassigned</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                <div><label class="label">Contact type</label><input v-model="form.contact_type" class="input" placeholder="Lead, Customer…" /></div>
                <div><label class="label">Timezone</label><input v-model="form.timezone" class="input" placeholder="e.g. America/Chicago" /><p v-if="err('timezone')" class="mt-1 text-xs text-red-600">{{ err('timezone') }}</p></div>
            </div>
            <div class="mt-3">
                <label class="label">Tags</label>
                <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-200 bg-white p-2">
                    <span v-for="t in form.tags" :key="t" class="badge gap-1">{{ t }}<button type="button" @click="form.tags.splice(form.tags.indexOf(t), 1)"><X class="size-3" /></button></span>
                    <input v-model="tagInput" class="min-w-24 flex-1 border-0 bg-transparent p-1 text-sm outline-none" placeholder="Add tag + Enter" @keydown.enter.prevent="addTag" @blur="addTag" />
                </div>
            </div>
            <div class="mt-3"><label class="label">Notes</label><textarea v-model="form.notes" rows="3" class="input" /></div>
            <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="form.email_opt_out" type="checkbox" class="rounded" /> Do not email this contact (opt-out)</label>
        </template>

        <div v-if="!$attrs.hideActions" class="flex justify-end gap-2 pt-4">
            <button type="button" class="btn-ghost" @click="emit('cancel')">Cancel</button>
            <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : contact ? 'Save changes' : 'Create contact' }}</button>
        </div>
    </form>
</template>
